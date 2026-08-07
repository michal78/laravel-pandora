<?php

declare(strict_types=1);

namespace Pandora\Tools;

use Illuminate\Contracts\Container\Container;
use Pandora\Exceptions\InvalidConfiguration;
use Pandora\Exceptions\ToolNotFound;
use Pandora\Providers\Data\ToolDefinition;
use Pandora\Tools\Schema\RuleSchemaGenerator;

/**
 * The catalogue of tools this application has installed — authorization
 * layer 1.
 *
 * A tool the registry does not know is denied outright, whatever the model
 * calls it. Registration is deployment-controlled: tools come from
 * `config/pandora.php` or from a discovery path, never from the database and
 * never from a model.
 *
 * Every tool's schema is generated at registration, so a tool with an
 * inexpressible rule fails when the application boots rather than in the
 * middle of somebody's conversation.
 */
final class ToolRegistry
{
    /** @var array<string, array<string, Tool>> name => version => tool */
    private array $tools = [];

    /** @var array<string, string> alias => name */
    private array $aliases = [];

    /** @var array<string, array<string, mixed>> "name@version" => schema */
    private array $schemas = [];

    public function __construct(
        private readonly Container $container,
        private readonly RuleSchemaGenerator $generator,
    ) {}

    /**
     * @param Tool|class-string<Tool> $tool
     */
    public function register(Tool|string $tool): self
    {
        $instance = $this->resolve($tool);
        $name = $instance->name();
        $version = $instance->version();

        if (isset($this->tools[$name][$version])) {
            throw InvalidConfiguration::make(
                "Tool [{$name}] version [{$version}] is registered twice. Bump version() or rename one.",
            );
        }

        // Generating now is what makes an inexpressible rule a boot-time
        // failure instead of a runtime surprise.
        $this->schemas[$name.'@'.$version] = $instance->schema($this->generator);

        foreach ($instance->aliases() as $alias) {
            if (isset($this->tools[$alias])) {
                throw InvalidConfiguration::make(
                    "Tool [{$name}] claims alias [{$alias}], which is already a registered tool name.",
                );
            }

            $this->aliases[$alias] = $name;
        }

        $this->tools[$name][$version] = $instance;

        // Highest version last, so resolving a bare name is an end-of-array read.
        uksort($this->tools[$name], static fn (string $a, string $b): int => version_compare($a, $b));

        return $this;
    }

    /**
     * @param list<Tool|class-string<Tool>> $tools
     */
    public function registerMany(array $tools): self
    {
        foreach ($tools as $tool) {
            $this->register($tool);
        }

        return $this;
    }

    /**
     * Resolve by name, alias, or the exact `name@version` form.
     *
     * A bare name resolves to the highest registered version: an old
     * conversation naming a tool without a version gets the current one, which
     * is what a caller means by "the refund tool".
     *
     * @throws ToolNotFound
     */
    public function get(string $reference): Tool
    {
        return $this->find($reference) ?? throw ToolNotFound::named($reference);
    }

    public function find(string $reference): ?Tool
    {
        [$name, $version] = $this->split($reference);

        $name = $this->aliases[$name] ?? $name;

        if (! isset($this->tools[$name])) {
            return null;
        }

        if ($version !== null) {
            return $this->tools[$name][$version] ?? null;
        }

        $versions = $this->tools[$name];
        $newest = array_key_last($versions);

        return $newest === null ? null : $versions[$newest];
    }

    public function has(string $reference): bool
    {
        return $this->find($reference) !== null;
    }

    /**
     * The JSON schema generated for a tool at registration.
     *
     * @return array<string, mixed>
     */
    public function schema(Tool $tool): array
    {
        return $this->schemas[$tool->name().'@'.$tool->version()]
            ?? $tool->schema($this->generator);
    }

    /**
     * Every registered tool, latest version of each, in registration order.
     *
     * @return list<Tool>
     */
    public function all(): array
    {
        $newest = [];

        foreach ($this->tools as $versions) {
            $key = array_key_last($versions);

            if ($key !== null) {
                $newest[] = $versions[$key];
            }
        }

        return $newest;
    }

    /**
     * Every registered version of every tool — what the Tools page shows when
     * an operator needs to see the whole catalogue including superseded ones.
     *
     * @return list<Tool>
     */
    public function allVersions(): array
    {
        $tools = [];

        foreach ($this->tools as $versions) {
            foreach ($versions as $tool) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    /**
     * @return list<Tool>
     */
    public function group(string $group): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (Tool $tool): bool => $tool->group() === $group,
        ));
    }

    /**
     * @return list<string>
     */
    public function groups(): array
    {
        $groups = array_map(static fn (Tool $tool): string => $tool->group(), $this->all());

        sort($groups);

        return array_values(array_unique($groups));
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    /**
     * Advertise a set of tools to a provider.
     *
     * The description sent to the model carries the deprecation notice, so a
     * model steers itself off a deprecated tool without anything breaking.
     *
     * @param list<Tool> $tools
     * @return list<ToolDefinition>
     */
    public function describe(array $tools): array
    {
        return array_map(fn (Tool $tool): ToolDefinition => new ToolDefinition(
            name: $tool->name(),
            description: $tool->deprecated() === null
                ? $tool->description()
                : $tool->description().' (Deprecated: '.$tool->deprecated().')',
            schema: $this->schema($tool),
        ), $tools);
    }

    public function flush(): self
    {
        $this->tools = [];
        $this->aliases = [];
        $this->schemas = [];

        return $this;
    }

    /**
     * @param Tool|class-string<Tool> $tool
     */
    private function resolve(Tool|string $tool): Tool
    {
        if ($tool instanceof Tool) {
            return $tool;
        }

        if (! class_exists($tool) || ! is_subclass_of($tool, Tool::class)) {
            throw InvalidConfiguration::make("[{$tool}] is not a Pandora tool class.");
        }

        /** @var Tool $instance */
        $instance = $this->container->make($tool);

        return $instance;
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function split(string $reference): array
    {
        if (! str_contains($reference, '@')) {
            return [$reference, null];
        }

        [$name, $version] = explode('@', $reference, 2);

        return [$name, $version];
    }
}
