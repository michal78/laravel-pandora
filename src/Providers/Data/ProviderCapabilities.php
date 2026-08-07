<?php

declare(strict_types=1);

namespace Pandora\Providers\Data;

/**
 * Queried BEFORE routing, so the router never sends a vision request to a
 * text-only model.
 */
final readonly class ProviderCapabilities implements \JsonSerializable
{
    public function __construct(
        public bool $streaming = false,
        public bool $tools = false,
        public bool $structuredOutput = false,
        public bool $vision = false,
        public bool $audio = false,
        public bool $embeddings = false,
    ) {}

    /**
     * @return array<string, bool>
     */
    public function jsonSerialize(): array
    {
        return [
            'streaming' => $this->streaming,
            'tools' => $this->tools,
            'structured_output' => $this->structuredOutput,
            'vision' => $this->vision,
            'audio' => $this->audio,
            'embeddings' => $this->embeddings,
        ];
    }
}
