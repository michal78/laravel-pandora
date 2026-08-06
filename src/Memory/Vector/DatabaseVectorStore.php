<?php

declare(strict_types=1);

namespace Pandora\Pandora\Memory\Vector;

use Pandora\Pandora\Contracts\VectorStore;
use Pandora\Pandora\Memory\Embedding;

/**
 * Brute-force cosine distance over the portable `vector` JSON column.
 *
 * Honest about what it is: it loads candidate vectors and compares them in
 * PHP. That is O(n) in the number of embeddings and it is the right default
 * anyway, because it works identically on all four engines with nothing
 * installed, and because the installations that have no vector database also
 * tend to be the ones with a few thousand memories rather than a few million.
 *
 * The point at which this stops being adequate is exactly the point at which
 * an operator should configure pgvector, and the contract makes that a
 * configuration change rather than a migration project.
 *
 * `isAvailable()` is always true. There is nothing to be unavailable: no
 * extension, no second host, no network.
 */
final class DatabaseVectorStore implements VectorStore
{
    /**
     * @param int $scanLimit how many stored vectors to compare against. Bounds
     *                       the memory this can consume: a million-row table
     *                       loaded into PHP is an outage, not a slow query.
     */
    public function __construct(
        private readonly int $scanLimit = 5000,
    ) {}

    public function key(): string
    {
        return 'database';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function upsert(string $ownerType, string $ownerId, array $vector): void
    {
        // The vector already lives on `pandora_embeddings`, written by the
        // embedder. This store indexes nothing of its own, so there is nothing
        // to keep in step -- which is also why it can never be stale.
    }

    public function forget(string $ownerType, string $ownerId): void
    {
        // Likewise: deleting the embedding row is the deletion.
    }

    public function search(string $ownerType, array $vector, int $limit): array
    {
        if ($vector === []) {
            return [];
        }

        $candidates = Embedding::query()
            ->where('owner_type', $ownerType)
            ->where('dimensions', count($vector))
            ->orderByDesc('id')
            ->limit($this->scanLimit)
            ->get();

        $matches = [];

        foreach ($candidates as $candidate) {
            $stored = $candidate->vector;

            // A stored vector of the wrong width is not comparable. It means
            // the embedding model changed without the rows being invalidated,
            // and silently zero-padding would produce confident nonsense.
            if (count($stored) !== count($vector)) {
                continue;
            }

            $matches[] = new VectorMatch(
                ownerId: $candidate->owner_id,
                distance: $this->cosineDistance($vector, $stored),
            );
        }

        usort(
            $matches,
            static fn (VectorMatch $a, VectorMatch $b): int => [$a->distance, $a->ownerId] <=> [$b->distance, $b->ownerId],
        );

        return array_slice($matches, 0, $limit);
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private function cosineDistance(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $value) {
            $dot += $value * $b[$i];
            $normA += $value * $value;
            $normB += $b[$i] * $b[$i];
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            // A zero vector has no direction, so it has no distance to
            // anything. Returning the maximum sorts it last instead of
            // producing a division by zero.
            return 2.0;
        }

        return 1.0 - ($dot / (sqrt($normA) * sqrt($normB)));
    }
}
