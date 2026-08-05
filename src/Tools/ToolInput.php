<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tools;

use Illuminate\Contracts\Support\Arrayable;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Validated tool arguments.
 *
 * A `ToolInput` only ever exists downstream of validation, so a tool's
 * `handle()` can trust its contents structurally. It cannot trust them
 * semantically -- the values still came from a model, and a model is
 * untrusted input.
 *
 * Tools wanting a typed value object call `as(MyInput::class)` rather than
 * changing their signature; the bag stays the contract, the DTO is a
 * convenience.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class ToolInput implements \JsonSerializable, Arrayable
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        private array $arguments = [],
    ) {}

    public function has(string $key): bool
    {
        return data_get($this->arguments, $key) !== null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->arguments, $key, $default);
    }

    public function string(string $key, ?string $default = null): ?string
    {
        $value = $this->get($key, $default);

        return $value === null ? null : (string) (is_scalar($value) ? $value : $default);
    }

    public function integer(string $key, ?int $default = null): ?int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, ?float $default = null): ?float
    {
        $value = $this->get($key);

        return is_numeric($value) ? (float) $value : $default;
    }

    public function boolean(string $key, ?bool $default = null): ?bool
    {
        $value = $this->get($key);

        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function array(string $key): array
    {
        $value = $this->get($key, []);

        return is_array($value) ? $value : [];
    }

    /**
     * Hydrate a value object from the validated arguments by matching
     * constructor parameter names.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     * @return T
     */
    public function as(string $class): object
    {
        $constructor = (new ReflectionClass($class))->getConstructor();

        if ($constructor === null) {
            return new $class;
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            $snake = (string) str($name)->snake();

            $value = $this->arguments[$name] ?? $this->arguments[$snake] ?? null;

            if ($value === null && $parameter->isDefaultValueAvailable()) {
                $arguments[$name] = $parameter->getDefaultValue();

                continue;
            }

            $arguments[$name] = $this->cast($value, $parameter->getType());
        }

        return new $class(...$arguments);
    }

    private function cast(mixed $value, ?\ReflectionType $type): mixed
    {
        if ($value === null || ! $type instanceof ReflectionNamedType || ! $type->isBuiltin()) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => is_numeric($value) ? (int) $value : $value,
            'float' => is_numeric($value) ? (float) $value : $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL),
            'string' => is_scalar($value) ? (string) $value : $value,
            default => $value,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->arguments;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->arguments;
    }
}
