<?php

declare(strict_types=1);

namespace Pandora\Pandora\Providers\Data;

/**
 * A tool as ADVERTISED to a provider.
 *
 * Provider-neutral by design: adapters translate this into whatever shape
 * their vendor expects. Nothing here reveals how the tool is implemented,
 * what it may touch, or who is allowed to call it -- the model is told what
 * it may ask for, never what will be checked when it does.
 */
final readonly class ToolDefinition implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $schema JSON Schema for the arguments.
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $schema,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'schema' => $this->schema,
        ];
    }
}
