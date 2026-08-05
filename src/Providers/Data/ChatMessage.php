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
    /**
     * @param list<ToolCall> $toolCalls Set on an assistant message that requested tools.
     */
    public function __construct(
        public MessageRole $role,
        public string $content,
        public ?string $toolCallId = null,
        public ?string $name = null,
        public array $toolCalls = [],
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
     * The assistant turn that REQUESTED tools.
     *
     * Every provider that accepts tool results requires the request that
     * produced them to be present too, so this message and its results travel
     * together or not at all.
     *
     * @param list<ToolCall> $toolCalls
     */
    public static function assistantToolCalls(string $content, array $toolCalls): self
    {
        return new self(MessageRole::Assistant, $content, toolCalls: $toolCalls);
    }

    /**
     * A tool's result, tied to the call it answers.
     *
     * The content is UNTRUSTED: it may be a web page, a database row or a
     * document, any of which can carry an instruction aimed at the model.
     */
    public static function tool(string $toolCallId, string $content, ?string $name = null): self
    {
        return new self(MessageRole::Tool, $content, toolCallId: $toolCallId, name: $name);
    }

    public function requestsTools(): bool
    {
        return $this->toolCalls !== [];
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
            'tool_calls' => $this->toolCalls === [] ? null : $this->toolCalls,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
