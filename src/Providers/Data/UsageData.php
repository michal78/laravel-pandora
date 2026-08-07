<?php

declare(strict_types=1);

namespace Pandora\Providers\Data;

/**
 * Normalised token accounting. Providers disagree about field names; adapters
 * absorb the disagreement so nothing downstream has to.
 */
final readonly class UsageData implements \JsonSerializable
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cachedInputTokens = 0,
        public int $cachedOutputTokens = 0,
        public int $reasoningTokens = 0,
        public int $audioUnits = 0,
        public int $imageUnits = 0,
        public int $requests = 1,
        public int $durationMs = 0,
    ) {}

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    public function plus(self $other): self
    {
        return new self(
            $this->inputTokens + $other->inputTokens,
            $this->outputTokens + $other->outputTokens,
            $this->cachedInputTokens + $other->cachedInputTokens,
            $this->cachedOutputTokens + $other->cachedOutputTokens,
            $this->reasoningTokens + $other->reasoningTokens,
            $this->audioUnits + $other->audioUnits,
            $this->imageUnits + $other->imageUnits,
            $this->requests + $other->requests,
            $this->durationMs + $other->durationMs,
        );
    }

    /**
     * @return array<string, int>
     */
    public function jsonSerialize(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cached_input_tokens' => $this->cachedInputTokens,
            'cached_output_tokens' => $this->cachedOutputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
            'audio_units' => $this->audioUnits,
            'image_units' => $this->imageUnits,
            'requests' => $this->requests,
            'duration_ms' => $this->durationMs,
        ];
    }
}
