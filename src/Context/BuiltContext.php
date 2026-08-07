<?php

declare(strict_types=1);

namespace Pandora\Context;

use Pandora\Providers\Data\ChatMessage;

/**
 * The assembled context, plus a record of what was included and what was
 * dropped. Context is never silently truncated -- omissions appear on the run
 * trace where someone debugging a bad answer will actually find them.
 */
final readonly class BuiltContext
{
    /**
     * @param list<ChatMessage> $messages
     * @param list<array{key: string, tokens: int, messages: int}> $included
     * @param list<array{key: string, reason: string}> $omitted
     */
    public function __construct(
        public array $messages,
        public array $included,
        public array $omitted,
        public int $estimatedTokens,
        public int $budget,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toTrace(): array
    {
        return [
            'estimated_tokens' => $this->estimatedTokens,
            'budget' => $this->budget,
            'message_count' => count($this->messages),
            'included' => $this->included,
            'omitted' => $this->omitted,
        ];
    }
}
