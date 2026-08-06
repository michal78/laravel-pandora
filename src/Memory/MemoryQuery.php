<?php

declare(strict_types=1);

namespace Pandora\Pandora\Memory;

use Pandora\Pandora\Memory\Enums\MemoryType;

/**
 * What a retrieval asks for -- everything except who is allowed to see it.
 *
 * The omission is the design. Visibility comes from `MemoryScopeSet`, which is
 * derived from the session; this object carries only the parts a caller (and,
 * through the `recall` tool, a model) may legitimately influence. There is
 * nowhere here to name a scope, a tenant or a user, because that is precisely
 * what must not be nameable.
 */
final readonly class MemoryQuery
{
    /**
     * @param list<MemoryType> $types an empty list means every type
     * @param int $limit results returned to the caller
     * @param int $candidateLimit rows fetched for ranking before the limit is
     *                            applied. Bounded so a broad query cannot pull
     *                            an entire tenant's memory into PHP.
     */
    public function __construct(
        public string $text,
        public array $types = [],
        public int $limit = 10,
        public int $candidateLimit = 200,
    ) {}

    public static function for(string $text): self
    {
        /** @var int $limit */
        $limit = config('pandora.memory.retrieval.limit', 10);
        /** @var int $candidates */
        $candidates = config('pandora.memory.retrieval.candidate_limit', 200);

        return new self($text, [], $limit, $candidates);
    }

    /**
     * @param list<MemoryType> $types
     */
    public function ofTypes(array $types): self
    {
        return new self($this->text, $types, $this->limit, $this->candidateLimit);
    }

    public function take(int $limit): self
    {
        return new self($this->text, $this->types, max(1, $limit), $this->candidateLimit);
    }
}
