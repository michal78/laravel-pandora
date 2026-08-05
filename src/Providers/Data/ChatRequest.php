<?php

declare(strict_types=1);

namespace Pandora\Pandora\Providers\Data;

/**
 * A provider-neutral model request.
 *
 * @property-read list<ChatMessage> $messages
 */
final readonly class ChatRequest
{
    /**
     * @param list<ChatMessage> $messages
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $model,
        public array $messages,
        public array $options = [],
        public ?int $maxTokens = null,
        public ?float $temperature = null,
        public bool $stream = false,
    ) {}

    public function withStreaming(bool $stream = true): self
    {
        return new self(
            $this->model,
            $this->messages,
            $this->options,
            $this->maxTokens,
            $this->temperature,
            $stream,
        );
    }

    /**
     * A redacted projection for the run trace.
     *
     * Message CONTENT is summarised rather than reproduced: the trace records
     * what was sent structurally, while the conversation itself is already
     * stored in `messages`. Duplicating full prompt text into every step would
     * multiply storage and widen the blast radius of a trace leak.
     *
     * @return array<string, mixed>
     */
    public function toTrace(): array
    {
        return [
            'model' => $this->model,
            'stream' => $this->stream,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'message_count' => count($this->messages),
            'messages' => array_map(static fn (ChatMessage $m): array => [
                'role' => $m->role->value,
                'chars' => mb_strlen($m->content),
            ], $this->messages),
            'options' => array_keys($this->options),
        ];
    }
}
