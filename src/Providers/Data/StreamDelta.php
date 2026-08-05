<?php

declare(strict_types=1);

namespace Pandora\Pandora\Providers\Data;

/**
 * One increment of a streamed response.
 */
final readonly class StreamDelta
{
    private function __construct(
        public StreamDeltaType $type,
        public string $text = '',
        public ?ToolCall $toolCall = null,
        public ?UsageData $usage = null,
    ) {}

    public static function text(string $text): self
    {
        return new self(StreamDeltaType::Text, $text);
    }

    public static function reasoning(string $text): self
    {
        return new self(StreamDeltaType::Reasoning, $text);
    }

    public static function toolCall(ToolCall $call): self
    {
        return new self(StreamDeltaType::ToolCall, toolCall: $call);
    }

    public static function usage(UsageData $usage): self
    {
        return new self(StreamDeltaType::Usage, usage: $usage);
    }

    public static function done(): self
    {
        return new self(StreamDeltaType::Done);
    }
}
