<?php

declare(strict_types=1);

namespace Pandora\Agents;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use Pandora\Contracts\AgentDefinition;
use Pandora\Exceptions\AgentNotFound;

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
     * The attributes a class definition owns for this agent, and which an
     * operator therefore must not edit.
     *
     * The control center needs this to render honestly. Without it the editor
     * would happily accept a change to a class-managed field, save it, and let
     * the next deploy revert it — reported months later as "Pandora lost my
     * settings", with nothing in the logs to explain it.
     *
     * `name` and `slug` are always included for a class-defined agent even
     * though a blueprint need not set them: `syncDefinition()` writes `name`
     * unconditionally, and the slug is the identity the definition is matched
     * by — editing it would orphan the row and mint a duplicate on next sync.
     *
     * A database-defined agent owns everything, so this is empty.
     *
     * @return list<string>
     */
    public function managedKeysFor(Agent $agent): array
    {
        $definitionClass = $agent->definition_class;

        if ($definitionClass === null) {
            return [];
        }

        // A definition can be deleted or renamed while its row survives. That
        // agent is no longer class-managed by anything, so its fields become
        // editable rather than permanently frozen by a class that is gone.
        if (! class_exists($definitionClass) || ! is_a($definitionClass, AgentDefinition::class, true)) {
            return [];
        }

        /** @var AgentDefinition $definition */
        $definition = $this->container->make($definitionClass);

        $managed = $definition->define(AgentBlueprint::for($agent->slug))->managedKeys();

        return array_values(array_unique([...$managed, 'name', 'slug']));
    }

    /**
     * Whether a definition class is still installed for a class-defined agent.
     *
     * Distinguishes "authoritative elsewhere" from "orphaned", which are very
     * different things to tell an operator.
     */
    public function definitionIsInstalled(Agent $agent): bool
    {
        $definitionClass = $agent->definition_class;

        return $definitionClass !== null
            && class_exists($definitionClass)
            && is_a($definitionClass, AgentDefinition::class, true);
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
