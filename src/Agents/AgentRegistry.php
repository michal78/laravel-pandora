<?php

declare(strict_types=1);

namespace Pandora\Pandora\Agents;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use Pandora\Pandora\Contracts\AgentDefinition;
use Pandora\Pandora\Exceptions\AgentNotFound;

/**
 * Resolves agents from class definitions and from the database.
 *
 * Class definitions are AUTHORITATIVE for the fields they set: they are
 * version-controlled and reviewed, so a control-center edit must not silently
 * override them at the next deploy. Fields a definition does not express stay
 * operator-editable, which keeps both audiences served.
 */
final class AgentRegistry
{
    /** @var array<string, class-string<AgentDefinition>> */
    private array $definitions = [];

    /** @var array<string, Agent> */
    private array $cache = [];

    private bool $synced = false;

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @param class-string<AgentDefinition> $definition
     */
    public function define(string $definition): self
    {
        $slug = $this->slugFor($definition);
        $this->definitions[$slug] = $definition;
        unset($this->cache[$slug]);

        return $this;
    }

    /**
     * @param list<class-string<AgentDefinition>> $definitions
     */
    public function defineMany(array $definitions): self
    {
        foreach ($definitions as $definition) {
            $this->define($definition);
        }

        return $this;
    }

    /**
     * @return array<string, class-string<AgentDefinition>>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    public function has(string $slug): bool
    {
        return isset($this->definitions[$slug])
            || Agent::query()->where('slug', $slug)->exists();
    }

    public function get(string $slug): Agent
    {
        if (isset($this->cache[$slug])) {
            return $this->cache[$slug];
        }

        if (isset($this->definitions[$slug])) {
            return $this->cache[$slug] = $this->syncDefinition($slug, $this->definitions[$slug]);
        }

        /** @var Agent|null $agent */
        $agent = Agent::query()->where('slug', $slug)->first();

        if ($agent === null) {
            throw AgentNotFound::slug($slug);
        }

        return $this->cache[$slug] = $agent;
    }

    /**
     * Every agent available in the current tenant, definitions synchronised.
     *
     * @return Collection<int, Agent>
     */
    public function all(): Collection
    {
        $this->syncAll();

        return Agent::query()->orderBy('name')->get()->values();
    }

    /**
     * @return Collection<int, Agent>
     */
    public function enabled(): Collection
    {
        return $this->all()->filter(static fn (Agent $a): bool => $a->enabled)->values();
    }

    /**
     * Synchronise every registered definition into the database.
     *
     * Idempotent, and cheap enough to call on demand rather than on boot --
     * doing it on boot would hit the database on every request, including ones
     * that never touch Pandora.
     */
    public function syncAll(bool $force = false): void
    {
        if ($this->synced && ! $force) {
            return;
        }

        foreach ($this->definitions as $slug => $definition) {
            $this->cache[$slug] = $this->syncDefinition($slug, $definition);
        }

        $this->synced = true;
    }

    public function flush(): void
    {
        $this->cache = [];
        $this->synced = false;
    }

    /**
     * @param class-string<AgentDefinition> $definitionClass
     */
    private function syncDefinition(string $slug, string $definitionClass): Agent
    {
        /** @var AgentDefinition $definition */
        $definition = $this->container->make($definitionClass);

        $blueprint = $definition->define(AgentBlueprint::for($slug));

        $attributes = $blueprint->toAttributes();
        $managed = $blueprint->managedKeys();

        /** @var array<string, mixed> $defaults */
        $defaults = config('pandora.agents.defaults', []);

        /** @var Agent|null $agent */
        $agent = Agent::query()->where('slug', $slug)->first();

        if ($agent === null) {
            return Agent::query()->create(
                array_merge($defaults, $attributes, ['definition_class' => $definitionClass]),
            );
        }

        // Only the fields the class actually expresses. Everything else keeps
        // whatever an operator configured in the control center.
        $updates = ['definition_class' => $definitionClass, 'name' => $attributes['name']];

        foreach ($managed as $key) {
            $updates[$key] = $attributes[$key];
        }

        $agent->fill($updates)->save();

        return $agent;
    }

    /**
     * A definition's slug, from an optional `slug()` method or its class name.
     *
     * @param class-string<AgentDefinition> $definition
     */
    private function slugFor(string $definition): string
    {
        if (method_exists($definition, 'slug')) {
            /** @var string $slug */
            $slug = $definition::slug();

            return $slug;
        }

        return str(class_basename($definition))
            ->beforeLast('Agent')
            ->whenEmpty(fn () => str(class_basename($definition)))
            ->kebab()
            ->toString();
    }
}
