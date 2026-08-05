<?php

declare(strict_types=1);

namespace Pandora\Pandora\Agents;

use Illuminate\Support\Str;
use Pandora\Pandora\Runs\Enums\AutonomyLevel;

/**
 * The fluent builder an AgentDefinition class configures.
 *
 * A blueprint is a plain value collector -- it touches no database. The
 * registry decides when and how to synchronise it, which keeps definitions
 * trivially unit-testable.
 *
 * Fields left unset are NOT synchronised, so an operator's control-center edit
 * to a field the class does not express is preserved across deploys.
 */
final class AgentBlueprint
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    public function __construct(
        private readonly string $slug,
    ) {}

    public static function for(string $slug): self
    {
        return new self($slug);
    }

    public function name(string $name): self
    {
        $this->attributes['name'] = $name;

        return $this;
    }

    public function description(string $description): self
    {
        $this->attributes['description'] = $description;

        return $this;
    }

    /**
     * The agent's persona and task instructions.
     *
     * This sets `role_instructions`. The framework's own safety boundary lives
     * in `system_instructions` and is not settable here by design.
     */
    public function instructions(string $instructions): self
    {
        $this->attributes['role_instructions'] = trim($instructions);

        return $this;
    }

    public function model(string $provider, string $model): self
    {
        $this->attributes['default_provider'] = $provider;
        $this->attributes['default_model'] = $model;

        return $this;
    }

    /**
     * Ordered failover chain, each entry as 'provider:model'.
     *
     * @param list<string> $models
     */
    public function fallback(array $models): self
    {
        $this->attributes['fallback_models'] = $models;

        return $this;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function options(array $options): self
    {
        $this->attributes['provider_options'] = $options;

        return $this;
    }

    public function maxIterations(int $iterations): self
    {
        $this->attributes['max_iterations'] = $iterations;

        return $this;
    }

    public function maxToolCalls(int $calls): self
    {
        $this->attributes['max_tool_calls'] = $calls;

        return $this;
    }

    public function timeout(int $seconds): self
    {
        $this->attributes['max_duration_seconds'] = $seconds;

        return $this;
    }

    public function contextBudget(int $tokens): self
    {
        $this->attributes['context_budget_tokens'] = $tokens;

        return $this;
    }

    public function autonomy(AutonomyLevel $level): self
    {
        $this->attributes['autonomy_level'] = $level->value;

        return $this;
    }

    public function enabled(bool $enabled = true): self
    {
        $this->attributes['enabled'] = $enabled;

        return $this;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function metadata(array $metadata): self
    {
        $this->attributes['metadata'] = $metadata;

        return $this;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return $this->attributes + [
            'slug' => $this->slug,
            'name' => Str::headline($this->slug),
        ];
    }

    /**
     * Only the keys the definition explicitly set -- the fields that are
     * authoritative over database edits.
     *
     * @return list<string>
     */
    public function managedKeys(): array
    {
        return array_keys($this->attributes);
    }
}
