<?php

declare(strict_types=1);

namespace Pandora\Pandora\Contracts;

use Pandora\Pandora\Memory\Vector\VectorMatch;

/**
 * An index over stored vectors.
 *
 * A vector store is an ACCELERATOR and never an authority. It proposes owner
 * ids in a useful order; whether any of them may be shown to the person
 * standing here is decided afterwards, in the database, by the same scope
 * constraint the lexical path uses. That division is what makes it safe to
 * plug in a third-party index that Pandora does not control, cannot audit, and
 * may be serving stale data from before a memory was forgotten.
 *
 * Consequently `search()` takes no scope, no tenant and no actor. There is
 * nothing useful an implementation could do with them, and an implementation
 * that tried would be the second place visibility is decided -- which is one
 * place too many.
 *
 * Every method may throw. Callers degrade to lexical retrieval and record the
 * degradation rather than failing the run: an unreachable index should cost
 * recall, not availability.
 */
interface VectorStore
{
    /**
     * A stable key used in configuration and on the trace, e.g. 'pgvector'.
     */
    public function key(): string;

    /**
     * Whether this store can serve a query right now.
     *
     * Consulted before use so that a missing extension or an unreachable host
     * degrades quietly at the first query instead of throwing on every one.
     */
    public function isAvailable(): bool;

    /**
     * Store or replace the vector for one owner.
     *
     * @param list<float> $vector
     */
    public function upsert(string $ownerType, string $ownerId, array $vector): void;

    /**
     * Remove an owner's vector.
     *
     * Called when a memory is forgotten. A soft-deleted row whose vector is
     * still indexed remains findable by the path that matters, so this is not
     * housekeeping -- it is the deletion.
     */
    public function forget(string $ownerType, string $ownerId): void;

    /**
     * Nearest owners to a query vector, closest first.
     *
     * @param list<float> $vector
     * @return list<VectorMatch>
     */
    public function search(string $ownerType, array $vector, int $limit): array;
}
