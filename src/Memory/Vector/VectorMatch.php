<?php

declare(strict_types=1);

namespace Pandora\Pandora\Memory\Vector;

/**
 * One candidate a vector store proposed.
 *
 * Carries the owner id and a distance, and deliberately nothing else -- no
 * content, no scope, no tenant. Whatever the store thinks it knows about a
 * memory is stale the moment the memory is edited, so the only thing worth
 * taking from it is the identifier and the ordering.
 *
 * `distance` is smaller-is-closer, whatever metric the store used. Callers
 * compare distances only within a single store's result set; comparing across
 * stores would be comparing metrics.
 */
final readonly class VectorMatch
{
    public function __construct(
        public string $ownerId,
        public float $distance,
    ) {}

    /**
     * Distance expressed as a 0..1 similarity, for ranking alongside lexical
     * scores.
     *
     * Monotonic and bounded, which is all that is needed: the absolute value
     * is not meaningful across metrics and is never presented as if it were.
     */
    public function similarity(): float
    {
        return 1 / (1 + max(0.0, $this->distance));
    }
}
