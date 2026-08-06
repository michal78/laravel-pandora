<?php

declare(strict_types=1);

namespace Pandora\Pandora\Memory;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Pandora\Pandora\Memory\Enums\MemoryType;
use Pandora\Pandora\Memory\Lexical\Tokeniser;

/**
 * The only way memory is read.
 *
 * Every retrieval in Pandora comes through here, which is what makes the scope
 * constraint auditable: there is one query to review, not one per feature. A
 * caller that assembles its own builder against `MemoryItem` has stepped
 * outside the thing this class exists to guarantee, and a code review should
 * treat it as it would a hand-rolled SQL string.
 *
 * The implementation is lexical and needs no vector database. That is the
 * shipped path, not a fallback nobody exercises -- an install with no
 * extensions must retrieve memory correctly, and Phase 4 is the reason this
 * package no longer trusts "optional" to mean "works".
 *
 * A vector store, when one is configured, changes the ORDER of these results
 * and never their VISIBILITY: it proposes candidates, and the candidates are
 * re-filtered by exactly the constraint below before anything is returned.
 */
final class MemoryRetriever
{
    public const STRATEGY_LEXICAL = 'lexical';

    /**
     * @return list<MemoryResult>
     */
    public function retrieve(MemoryScopeSet $scopes, MemoryQuery $query): array
    {
        $tokens = Tokeniser::tokenise($query->text);

        if ($tokens === []) {
            // A query of nothing but stop words matches nothing. Returning the
            // most recent memories instead would mean an empty prompt quietly
            // retrieves whatever is newest, which is how unrelated facts end
            // up in an answer.
            return [];
        }

        $candidates = $this->candidates($scopes, $query, $tokens);

        $results = [];

        foreach ($candidates as $item) {
            $matched = $this->matchedTokens($item, $tokens);

            if ($matched === []) {
                continue;
            }

            $results[] = new MemoryResult(
                item: $item,
                score: $this->score($item, $tokens, $matched),
                matchedTokens: $matched,
                strategy: self::STRATEGY_LEXICAL,
            );
        }

        usort($results, static function (MemoryResult $a, MemoryResult $b): int {
            // Tie-broken by id so the same corpus and the same query always
            // produce the same order. An unstable sort here makes a flaky test
            // that only fails on one engine.
            return [$b->score, $b->item->getKey()] <=> [$a->score, $a->item->getKey()];
        });

        $results = array_slice($results, 0, $query->limit);

        $this->recordRetrieval($results);

        return $results;
    }

    /**
     * @param list<string> $tokens
     * @return list<MemoryItem>
     */
    private function candidates(MemoryScopeSet $scopes, MemoryQuery $query, array $tokens): array
    {
        // `acrossAllTenants` looks alarming and is correct: the scope set
        // reapplies the tenant predicate per branch, because installation-wide
        // memory is tenant-less and an AND-ed tenant filter could never match
        // it. The constraint is not being skipped -- it is being applied by
        // the one class that owns it.
        $builder = MemoryItem::acrossAllTenants();

        $builder->retrievable();

        $scopes->constrain($builder);

        if ($query->types !== []) {
            $builder->whereIn('type', array_map(
                static fn (MemoryType $type): string => $type->value,
                $query->types,
            ));
        }

        $builder->where(/** @param Builder<MemoryItem> $q */ function (Builder $q) use ($tokens): void {
            foreach ($tokens as $token) {
                // Tokens contain only letters and digits, so there is nothing
                // here for a LIKE wildcard to do -- but the escape stays,
                // because the day the tokeniser changes is not the day anyone
                // will remember this line.
                $like = '%'.addcslashes($token, '%_\\').'%';

                // `lower(column) LIKE lowercased-token`, not a bare LIKE.
                // PostgreSQL's LIKE is case-sensitive; SQLite's, MySQL's and
                // MariaDB's are not. A bare LIKE therefore passes on three
                // engines and silently retrieves nothing on the fourth --
                // no error, no empty-result signal, just an agent that has
                // forgotten everything written with a capital letter.
                //
                // `lower()` exists on all four. It is ASCII-only in SQLite
                // built without ICU, so non-ASCII case folding is
                // engine-dependent; that is documented in the memory guide
                // rather than papered over. Scripts without case, and
                // lowercase ASCII, behave identically everywhere.
                $q->orWhereRaw('lower(content) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(title, \'\')) like ?', [$like]);
            }
        });

        /** @var list<MemoryItem> $items */
        $items = $builder
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($query->candidateLimit)
            ->get()
            ->all();

        return $items;
    }

    /**
     * @param list<string> $tokens
     * @return list<string>
     */
    private function matchedTokens(MemoryItem $item, array $tokens): array
    {
        // Re-tokenising the row rather than trusting the LIKE means "cat"
        // does not count as a match inside "catalogue". The database narrows
        // the candidate set; this decides what actually matched.
        $itemTokens = Tokeniser::tokenise($item->embeddableText());

        return array_values(array_intersect($tokens, $itemTokens));
    }

    /**
     * @param list<string> $tokens
     * @param list<string> $matched
     */
    private function score(MemoryItem $item, array $tokens, array $matched): float
    {
        // Share of the question this memory answers. The dominant term, so a
        // memory matching two of two words outranks one matching two of nine.
        $overlap = count($matched) / count($tokens);

        // A title match is a stronger signal than a body match: titles are
        // written to be the summary, bodies accumulate incidental words.
        $titleTokens = $item->title === null ? [] : Tokeniser::tokenise($item->title);
        $titleHits = count(array_intersect($matched, $titleTokens));
        $titleBonus = $titleTokens === [] ? 0.0 : 0.15 * ($titleHits / count($tokens));

        // Confidence is a stated belief, not evidence, so it adjusts rather
        // than decides.
        $confidence = 0.1 * ($item->confidence / 100);

        // Recency, tapering over roughly three months. Enough to prefer a
        // fresh fact over a stale one of equal overlap, never enough to
        // outrank a better match.
        $ageDays = $item->created_at === null
            ? 0.0
            : max(0.0, (float) $item->created_at->diffInDays(Date::now(), absolute: true));
        $recency = 0.1 * (1 / (1 + ($ageDays / 90)));

        return $overlap + $titleBonus + $confidence + $recency;
    }

    /**
     * @param list<MemoryResult> $results
     */
    private function recordRetrieval(array $results): void
    {
        if ($results === []) {
            return;
        }

        $ids = array_map(static fn (MemoryResult $r): string => (string) $r->item->getKey(), $results);

        // Through the base builder, so `updated_at` is left alone. "When was
        // this memory last used" and "when was it last changed" are different
        // questions and the Memory page shows both.
        MemoryItem::acrossAllTenants()
            ->whereIn('id', $ids)
            ->toBase()
            ->update([
                'last_retrieved_at' => Date::now(),
                'retrieval_count' => MemoryItem::query()->getConnection()->raw('retrieval_count + 1'),
            ]);
    }
}
