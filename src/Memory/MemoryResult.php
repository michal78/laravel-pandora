<?php

declare(strict_types=1);

namespace Pandora\Pandora\Memory;

/**
 * One retrieved memory and why it was retrieved.
 *
 * The score and the matched tokens are carried out of the retriever rather
 * than discarded, because "why did it tell them that?" is the question memory
 * generates, and an answer of "it seemed relevant" is not one.
 */
final readonly class MemoryResult
{
    /**
     * @param list<string> $matchedTokens
     */
    public function __construct(
        public MemoryItem $item,
        public float $score,
        public array $matchedTokens,
        public string $strategy,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toTrace(): array
    {
        return [
            'id' => $this->item->getKey(),
            'scope' => $this->item->scope->value,
            'type' => $this->item->type->value,
            'score' => round($this->score, 4),
            'matched' => $this->matchedTokens,
            'strategy' => $this->strategy,
        ];
    }
}
