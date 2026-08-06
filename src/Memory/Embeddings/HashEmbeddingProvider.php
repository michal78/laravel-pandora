<?php

declare(strict_types=1);

namespace Pandora\Pandora\Memory\Embeddings;

use Pandora\Pandora\Contracts\EmbeddingProvider;
use Pandora\Pandora\Memory\Lexical\Tokeniser;

/**
 * A deterministic, offline embedding provider.
 *
 * Not a language model and not pretending to be one. It hashes tokens into a
 * fixed number of buckets -- the "hashing trick" -- which gives a vector where
 * texts sharing words land near each other and texts sharing none do not. That
 * is enough to exercise every part of the vector path honestly: the contract,
 * the store, the cache, the scope re-filter, and the pgvector adapter in CI.
 *
 * It is the default because the alternative defaults are worse. A null
 * provider means the vector path is never exercised, which is the Phase 4
 * failure repeated on purpose. A hosted provider means the test suite makes
 * paid network calls, so it gets skipped, which is the same failure wearing a
 * different hat.
 *
 * What it does not give you is semantics: it will not put "car" near
 * "automobile", because nothing here knows they are related. For that,
 * configure a real embedding provider. The contract is the same.
 */
final class HashEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(
        private readonly int $dimensions = 256,
    ) {}

    public function key(): string
    {
        return 'hash';
    }

    public function model(): string
    {
        return 'hash-'.$this->dimensions;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function embed(string $text): array
    {
        $vector = array_fill(0, $this->dimensions, 0.0);
        $tokens = Tokeniser::tokenise($text);

        if ($tokens === []) {
            return $vector;
        }

        foreach ($tokens as $token) {
            // crc32 rather than a cryptographic hash: this is a bucket
            // assignment, not a security boundary, and it must produce the
            // same bucket on every platform and PHP version.
            $bucket = crc32($token) % $this->dimensions;

            // Sign derived from a second hash so that two different tokens in
            // the same bucket do not always reinforce each other.
            $sign = (crc32('sign:'.$token) % 2) === 0 ? 1.0 : -1.0;

            $vector[$bucket] += $sign;
        }

        // L2-normalise, so cosine distance behaves and a long document does
        // not outrank a short one purely by having more words.
        $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $vector)));

        if ($norm <= 0.0) {
            return array_values($vector);
        }

        return array_values(array_map(static fn (float $v): float => $v / $norm, $vector));
    }

    public function embedBatch(array $texts): array
    {
        return array_map($this->embed(...), $texts);
    }
}
