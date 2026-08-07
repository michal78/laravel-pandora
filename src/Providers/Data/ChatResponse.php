<?php

declare(strict_types=1);

namespace Pandora\Providers\Data;

/**
 * A provider-neutral model response.
 */
final readonly class ChatResponse
{
    /**
     * @param list<ToolCall> $toolCalls
     * @param array<string, mixed> $rawMeta Unmapped vendor fields, for debugging only.
     */
    public function __construct(
        public string $content,
        public FinishReason $finishReason,
        public UsageData $usage,
        public array $toolCalls = [],
        public ?string $reasoningSummary = null,
        public ?string $providerResponseId = null,
        public array $rawMeta = [],
    ) {}

    /**
     * Whether this response ends the run, as opposed to requesting tools.
     */
    public function isFinal(): bool
    {
        return $this->toolCalls === [] && $this->finishReason->isFinal();
    }

    /**
     * @return array<string, mixed>
     */
    public function toTrace(): array
    {
        return [
            'finish_reason' => $this->finishReason->value,
            'content_chars' => mb_strlen($this->content),
            'tool_calls' => array_map(
                static fn (ToolCall $c): array => ['id' => $c->id, 'name' => $c->name],
                $this->toolCalls,
            ),
            // Present only when the provider genuinely exposes one. Pandora
            // never fabricates a reasoning summary and never depends on it.
            'has_reasoning_summary' => $this->reasoningSummary !== null,
            'usage' => $this->usage->jsonSerialize(),
            'provider_response_id' => $this->providerResponseId,
        ];
    }
}
