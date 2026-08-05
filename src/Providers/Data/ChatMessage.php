<?php

declare(strict_types=1);

namespace Pandora\Pandora\Providers\Data;

use Pandora\Pandora\Messages\Enums\MessageRole;

/**
 * A message as sent TO a provider. Deliberately separate from the Message
 * Eloquent model: what we store and what we send are different concerns and
 * should be free to diverge.
 */
final readonly class ChatMessage implements \JsonSerializable
{
    public function __construct(
        public MessageRole $role,
        public string $content,
        public ?string $toolCallId = null,
        public ?string $name = null,
    ) {}

    public static function system(string $content): self
    {
        return new self(MessageRole::System, $content);
    }

    public static function user(string $content): self
    {
        return new self(MessageRole::User, $content);
    }

    public static function assistant(string $content): self
    {
        return new self(MessageRole::Assistant, $content);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter([
            'role' => $this->role->value,
            'content' => $this->content,
            'tool_call_id' => $this->toolCallId,
            'name' => $this->name,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
