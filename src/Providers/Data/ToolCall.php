<?php

declare(strict_types=1);

namespace Pandora\Pandora\Providers\Data;

/**
 * A model's REQUEST to use a tool. Not an execution, and never an instruction:
 * it is validated, policy-checked and authorized exactly as if it had arrived
 * as an unauthenticated request from the internet.
 */
final readonly class ToolCall implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'arguments' => $this->arguments];
    }
}
