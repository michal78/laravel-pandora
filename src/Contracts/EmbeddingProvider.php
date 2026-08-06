<?php

declare(strict_types=1);

namespace Pandora\Pandora\Contracts;

/**
 * Turns text into a vector.
 *
 * Separate from `Provider` on purpose. An embedding model is not a chat model:
 * it is chosen once and then effectively frozen, because changing it
 * invalidates every vector already stored. A host that wants a different
 * embedding provider from its chat provider -- a local model for embeddings, a
 * hosted one for generation -- is the normal case, not an exotic one.
 *
 * Implementations MUST be deterministic for the same (text, model) pair.
 * Retrieval caches on a content hash, and a provider that returns a slightly
 * different vector each call makes that cache a source of drift rather than a
 * saving.
 */
interface EmbeddingProvider
{
    /**
     * A stable key used in `pandora_embeddings.provider_key`, e.g. 'openai'.
     */
    public function key(): string;

    /**
     * The model this provider embeds with, e.g. 'text-embedding-3-small'.
     *
     * Stored alongside every vector so a change is detectable. Two vector
     * spaces in one column makes every distance meaningless, and nothing about
     * the numbers themselves would reveal it.
     */
    public function model(): string;

    /**
     * How many components the vectors have.
     */
    public function dimensions(): int;

    /**
     * Embed one string.
     *
     * @return list<float>
     */
    public function embed(string $text): array;

    /**
     * Embed several strings in one call.
     *
     * Separate from `embed()` because every hosted embedding API charges and
     * rate-limits per request, and re-embedding a corpus one string at a time
     * is the difference between a minute and an afternoon. Implementations
     * that have no batch endpoint may loop.
     *
     * @param list<string> $texts
     * @return list<list<float>>
     */
    public function embedBatch(array $texts): array;
}
