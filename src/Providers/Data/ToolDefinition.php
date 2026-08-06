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
     * The schema in a form that survives `json_encode`.
     *
     * PHP cannot tell an empty map from an empty list, and `json_encode` has
     * to guess: `[]`. JSON Schema says `properties` is an object, so a tool
     * that takes no arguments advertises `"properties": []` and a strict
     * provider rejects the entire request -- every tool in it, not just the
     * one at fault. OpenAI does exactly that:
     *
     *     Invalid schema for function 'inspect_run_status':
     *     [] is not of type 'object'.
     *
     * So the conversion happens once, here, rather than in each adapter:
     * every one of them passes this straight to `json_encode`, and the next
     * adapter written would have had to remember independently.
     *
     * Keys whose value is a JSON *list* are left alone -- an empty `required`
     * is `[]` and must stay `[]`.
     *
     * @return \stdClass|array<string, mixed>
     */
    public function encodableSchema(): \stdClass|array
    {
        /** @var \stdClass|array<string, mixed> $encodable */
        $encodable = self::objectify($this->schema, null);

        return $encodable;
    }

    /**
     * Keys that hold a JSON array in JSON Schema, and must not become objects.
     *
     * @var list<string>
     */
    private const LIST_KEYS = ['required', 'enum', 'examples', 'anyOf', 'oneOf', 'allOf'];

    private static function objectify(mixed $value, ?string $key): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($value === []) {
            return in_array($key, self::LIST_KEYS, true) ? [] : new \stdClass;
        }

        $converted = [];

        foreach ($value as $childKey => $childValue) {
            // A numeric key means a list position -- `anyOf[0]` is a schema,
            // not a member of `anyOf`, so the parent key must not carry down.
            $converted[$childKey] = self::objectify(
                $childValue,
                is_string($childKey) ? $childKey : null,
            );
        }

        return $converted;
    }

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
