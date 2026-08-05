<?php

declare(strict_types=1);

namespace Pandora\Pandora\Context;

use Pandora\Pandora\Providers\Data\ChatMessage;

/**
 * One provider's contribution to a model request.
 */
final readonly class ContextSection
{
    /**
     * @param list<ChatMessage> $messages
     */
    public function __construct(
        public string $key,
        public array $messages,
        public int $estimatedTokens,
    ) {}

    /**
     * @param list<ChatMessage> $messages
     */
    public static function of(string $key, array $messages): self
    {
        $characters = array_sum(array_map(
            static fn (ChatMessage $m): int => mb_strlen($m->content),
            $messages,
        ));

        // ~4 characters per token. Deliberately crude: the budget is a
        // guardrail, and a provider-accurate tokeniser would add a dependency
        // for precision the guardrail does not need.
        return new self($key, $messages, (int) ceil($characters / 4));
    }
}
