<?php

declare(strict_types=1);

namespace Pandora\Pandora\Automation;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Pandora\Pandora\Exceptions\AutomationRefused;

/**
 * Conditional polling: the check an automation runs before it decides it has
 * anything to do.
 *
 * A condition is NAMED in the automation row and DEFINED in
 * `config/pandora.automation.conditions`. The same rule as tools, jobs and
 * readable config keys: the database says which, the host's version-controlled
 * configuration says what. A callable read out of a database row is remote code
 * execution with extra steps, and an automations page is exactly the surface an
 * attacker would want it on.
 *
 * A name that is not registered REFUSES the occurrence. It does not evaluate
 * true and it does not evaluate false: an automation whose condition has been
 * renamed out from under it must stop, not guess.
 */
final class ConditionRegistry
{
    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
    ) {}

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        /** @var array<string, mixed> $conditions */
        $conditions = $this->config->get('pandora.automation.conditions', []);

        return $conditions;
    }

    /**
     * Evaluate the condition an automation names.
     *
     * An automation with no condition is unconditional -- true.
     *
     * @throws AutomationRefused when the name is not registered
     */
    public function evaluate(Automation $automation): bool
    {
        $condition = $automation->condition;

        if ($condition === null || $condition === []) {
            return true;
        }

        $name = $condition['name'] ?? null;

        if (! is_string($name) || $name === '') {
            return true;
        }

        if (! $this->has($name)) {
            throw AutomationRefused::unknownCondition($automation->slug, $name);
        }

        /** @var array<string, mixed> $arguments */
        $arguments = is_array($condition['arguments'] ?? null) ? $condition['arguments'] : [];

        return (bool) $this->call($this->all()[$name], $arguments);
    }

    /**
     * A condition may be a closure defined in the config file, or a class with
     * an `__invoke`. Both are host code; neither came from a database row.
     *
     * @param array<string, mixed> $arguments
     */
    private function call(mixed $condition, array $arguments): mixed
    {
        if (is_string($condition) && class_exists($condition)) {
            $condition = $this->container->make($condition);
        }

        if (! is_callable($condition)) {
            return false;
        }

        return $condition($arguments);
    }
}
