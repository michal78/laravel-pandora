<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Pandora\Pandora\Memory\Enums\MemoryScope;
use Pandora\Pandora\Memory\Enums\MemorySource;
use Pandora\Pandora\Memory\Enums\MemoryStatus;
use Pandora\Pandora\Memory\Enums\MemoryType;
use Pandora\Pandora\Memory\Lexical\Tokeniser;
use Pandora\Pandora\Memory\MemoryItem;
use Pandora\Pandora\Memory\MemoryQuery;
use Pandora\Pandora\Memory\MemoryRetriever;
use Pandora\Pandora\Memory\MemoryScopeSet;

/**
 * Phase 5, criteria 6 to 8 -- retrieval with nothing installed.
 *
 * This is the shipped path, not a fallback. A default install has no vector
 * database, no search extension and no full-text index, and it must still
 * answer "what do you know about X" correctly on SQLite, MySQL, MariaDB and
 * PostgreSQL. Phase 4 is the reason this file exists in this shape: an
 * optional dependency that is untested by default is a defect waiting for the
 * first person who does not install it.
 */
beforeEach(function (): void {
    $this->retriever = app(MemoryRetriever::class);
    $this->scopes = MemoryScopeSet::of([
        ['scope' => MemoryScope::Tenant, 'scope_id' => null],
    ]);
});

/**
 * @param array<string, mixed> $attributes
 */
function lexicalMemory(string $content, array $attributes = []): MemoryItem
{
    /** @var MemoryItem $item */
    $item = MemoryItem::query()->create(array_merge([
        'scope' => MemoryScope::Tenant->value,
        'scope_id' => null,
        'type' => MemoryType::UserFact->value,
        'content' => $content,
        'source' => MemorySource::User->value,
    ], $attributes));

    return $item;
}

it('retrieves with no vector store configured', function (): void {
    expect(config('pandora.memory.vector_store'))->toBeNull();

    $item = lexicalMemory('Deploys go out on Thursday afternoons.');

    $results = $this->retriever->retrieve($this->scopes, MemoryQuery::for('when do deploys happen'));

    expect($results)->toHaveCount(1)
        ->and($results[0]->item->getKey())->toBe($item->getKey())
        ->and($results[0]->strategy)->toBe(MemoryRetriever::STRATEGY_LEXICAL);
});

it('ranks a memory answering more of the question above one answering less', function (): void {
    $both = lexicalMemory('The staging database runs on Postgres.');
    $one = lexicalMemory('The mailer runs on Postmark.');

    $results = $this->retriever->retrieve(
        $this->scopes,
        MemoryQuery::for('staging database'),
    );

    expect($results[0]->item->getKey())->toBe($both->getKey())
        ->and($results[0]->score)->toBeGreaterThan(0.9);

    // The weaker candidate matched nothing at all, so it is absent rather
    // than present with a low score -- a retrieval that always returns
    // something is a retrieval that hallucinates relevance.
    expect(array_map(fn ($r) => $r->item->getKey(), $results))
        ->not->toContain($one->getKey());
});

it('prefers a title match over an equal body match', function (): void {
    $titled = lexicalMemory('Something incidental about it.', [
        'title' => 'Deployment window',
    ]);
    $untitled = lexicalMemory('Deployment window is mentioned here in passing.');

    $results = $this->retriever->retrieve($this->scopes, MemoryQuery::for('deployment window'));

    expect($results)->toHaveCount(2)
        ->and($results[0]->item->getKey())->toBe($titled->getKey())
        ->and($results[1]->item->getKey())->toBe($untitled->getKey());
});

it('matches regardless of the case a memory was written in', function (): void {
    // The engine-difference test. PostgreSQL's LIKE is case-sensitive and the
    // other three engines' are not, so a bare LIKE passes on SQLite, MySQL and
    // MariaDB while retrieving nothing on Postgres -- silently, with no error
    // and no empty-result signal. Every capitalisation below must come back on
    // all four engines.
    $upper = lexicalMemory('DEPLOYMENT WINDOW IS THURSDAY.');
    $title = lexicalMemory('Something else entirely.', ['title' => 'Deployment Window']);
    $mixed = lexicalMemory('The DePlOyMeNt window moved.');

    $ids = array_map(
        fn ($r) => $r->item->getKey(),
        $this->retriever->retrieve($this->scopes, MemoryQuery::for('deployment')),
    );

    expect($ids)->toEqualCanonicalizing([
        $upper->getKey(), $title->getKey(), $mixed->getKey(),
    ]);
});

it('finds a memory from a capitalised query', function (): void {
    $item = lexicalMemory('deployment window is thursday.');

    $results = $this->retriever->retrieve($this->scopes, MemoryQuery::for('DEPLOYMENT Window'));

    expect($results)->toHaveCount(1)
        ->and($results[0]->item->getKey())->toBe($item->getKey());
});

it('does not match a token inside a longer word', function (): void {
    lexicalMemory('The catalogue is published quarterly.');

    expect($this->retriever->retrieve($this->scopes, MemoryQuery::for('cat')))->toBe([]);
});

it('returns nothing for a query of only stop words', function (): void {
    lexicalMemory('The deploy is on Thursday.');

    foreach (['the and of', 'is it', 'a'] as $query) {
        expect($this->retriever->retrieve($this->scopes, MemoryQuery::for($query)))
            ->toBe([], "query [{$query}] returned something");
    }
});

it('is deterministic across repeated identical queries', function (): void {
    foreach (range(1, 8) as $n) {
        lexicalMemory("Runbook step {$n} mentions rollback.");
    }

    $first = array_map(
        fn ($r) => $r->item->getKey(),
        $this->retriever->retrieve($this->scopes, MemoryQuery::for('rollback runbook')),
    );

    foreach (range(1, 5) as $ignored) {
        $again = array_map(
            fn ($r) => $r->item->getKey(),
            $this->retriever->retrieve($this->scopes, MemoryQuery::for('rollback runbook')),
        );

        expect($again)->toBe($first);
    }
});

it('bounds the result set to the requested limit', function (): void {
    foreach (range(1, 20) as $n) {
        lexicalMemory("Note {$n} about rollback.");
    }

    expect($this->retriever->retrieve($this->scopes, MemoryQuery::for('rollback')))
        ->toHaveCount(10);

    expect($this->retriever->retrieve($this->scopes, MemoryQuery::for('rollback')->take(3)))
        ->toHaveCount(3);
});

it('filters by type when asked', function (): void {
    lexicalMemory('Rollback is a user fact.', ['type' => MemoryType::UserFact->value]);
    $summary = lexicalMemory('Rollback is a summary.', ['type' => MemoryType::Summary->value]);

    $results = $this->retriever->retrieve(
        $this->scopes,
        MemoryQuery::for('rollback')->ofTypes([MemoryType::Summary]),
    );

    expect($results)->toHaveCount(1)
        ->and($results[0]->item->getKey())->toBe($summary->getKey());
});

it('excludes suggested, rejected, expired and deleted items', function (): void {
    $active = lexicalMemory('Rollback is active.');
    lexicalMemory('Rollback is suggested.', ['status' => MemoryStatus::Suggested->value]);
    lexicalMemory('Rollback is rejected.', ['status' => MemoryStatus::Rejected->value]);
    lexicalMemory('Rollback is marked expired.', ['status' => MemoryStatus::Expired->value]);
    lexicalMemory('Rollback expired by date.', ['expires_at' => Date::now()->subMinute()]);
    lexicalMemory('Rollback is deleted.')->delete();

    $results = $this->retriever->retrieve($this->scopes, MemoryQuery::for('rollback'));

    expect($results)->toHaveCount(1)
        ->and($results[0]->item->getKey())->toBe($active->getKey());
});

it('records that a memory was used without touching when it was changed', function (): void {
    $item = lexicalMemory('Rollback happens on Thursday.');
    $changedAt = $item->refresh()->updated_at;

    expect($item->retrieval_count)->toBe(0)
        ->and($item->last_retrieved_at)->toBeNull();

    $this->retriever->retrieve($this->scopes, MemoryQuery::for('rollback'));
    $this->retriever->retrieve($this->scopes, MemoryQuery::for('rollback'));

    $item->refresh();

    expect($item->retrieval_count)->toBe(2)
        ->and($item->last_retrieved_at)->not->toBeNull()
        ->and($item->updated_at?->equalTo($changedAt))->toBeTrue();
});

it('reports what matched, for the run trace', function (): void {
    lexicalMemory('The staging database runs on Postgres.');

    $results = $this->retriever->retrieve($this->scopes, MemoryQuery::for('staging database'));
    $trace = $results[0]->toTrace();

    expect($trace['matched'])->toEqualCanonicalizing(['staging', 'database'])
        ->and($trace['strategy'])->toBe('lexical')
        ->and($trace['scope'])->toBe('tenant');
});

it('tokenises non-Latin scripts rather than discarding them', function (): void {
    expect(Tokeniser::tokenise('Café Ω 東京 42'))->toBe(['café', '東京', '42']);

    lexicalMemory('The 東京 office opens at nine.');

    expect($this->retriever->retrieve($this->scopes, MemoryQuery::for('東京')))->toHaveCount(1);
});

it('drops stop words and single characters when tokenising', function (): void {
    expect(Tokeniser::tokenise('The a of X deploy'))->toBe(['deploy'])
        ->and(Tokeniser::tokenise('Deploy DEPLOY deploy'))->toBe(['deploy'])
        ->and(Tokeniser::tokenise('   '))->toBe([]);
});
