<?php

declare(strict_types=1);

namespace Pandora\Tools\Schema;

use BackedEnum;
use Closure;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Pandora\Exceptions\UnsupportedValidationRule;
use ReflectionClass;
use Stringable;

/**
 * Turns a tool's Laravel validation rules into the JSON schema advertised to
 * the model.
 *
 * One source of truth: the rules validate the model's arguments AND describe
 * them. Hand-writing the schema alongside the rules guarantees the two drift,
 * and a drifted schema is a tool that tells the model to send something it
 * will then reject.
 *
 * Three categories of rule:
 *
 *  - **Mapped** — expressible as a schema constraint, and emitted.
 *  - **Runtime-only** — enforced by validation but not expressible (`exists`,
 *    `required_if`, a Rule object, a closure). These only ever NARROW what is
 *    accepted, so omitting them makes the schema less specific, never wrong.
 *  - **Unsupported** — anything else, and anything whose meaning depends on a
 *    type the tool did not declare. These THROW at registration.
 *
 * See docs/adr/0007-tools-are-classes-with-laravel-authorization.md.
 */
final class RuleSchemaGenerator
{
    /**
     * Rules enforced at call time that deliberately do not appear in the
     * schema. Listed explicitly so an unknown rule can still fail loudly.
     *
     * @var list<string>
     */
    private const RUNTIME_ONLY = [
        'accepted', 'accepted_if', 'active_url', 'after', 'after_or_equal', 'ascii',
        'bail', 'before', 'before_or_equal', 'confirmed', 'current_password',
        'declined', 'declined_if', 'different', 'doesnt_end_with', 'doesnt_start_with',
        'exclude', 'exclude_if', 'exclude_unless', 'exclude_with', 'exclude_without',
        'exists', 'filled', 'hex_color', 'in_array', 'lowercase', 'mac_address',
        'missing', 'missing_if', 'missing_unless', 'missing_with', 'missing_with_all',
        'multiple_of', 'present', 'prohibited', 'prohibited_if', 'prohibited_unless',
        'prohibits', 'required_array_keys', 'required_if', 'required_if_accepted',
        'required_if_declined', 'required_unless', 'required_with', 'required_with_all',
        'required_without', 'required_without_all', 'same', 'sometimes', 'unique',
        'uppercase',
    ];

    /**
     * Upload rules. A tool receives JSON from a model, never a file, so these
     * are always a mistake rather than merely unsupported.
     *
     * @var list<string>
     */
    private const FILE_RULES = [
        'file', 'image', 'mimes', 'mimetypes', 'dimensions', 'extensions', 'uploaded',
    ];

    /** @var array<string, string> */
    private const TYPES = [
        'string' => 'string',
        'integer' => 'integer',
        'int' => 'integer',
        'numeric' => 'number',
        'decimal' => 'number',
        'boolean' => 'boolean',
        'bool' => 'boolean',
        'array' => 'array',
        'list' => 'array',
    ];

    /**
     * Rules whose meaning depends on the field's type. Without a declared type
     * `min:1` could mean "at least 1", "at least one character" or "at least
     * one element" -- three different schemas. We refuse to guess.
     *
     * @var list<string>
     */
    private const TYPE_DEPENDENT = ['min', 'max', 'between', 'size', 'gt', 'gte', 'lt', 'lte'];

    /**
     * @param array<string, mixed> $rules Field => rule string, array or objects.
     * @param array<string, string> $descriptions Field => human description for the model.
     * @return array<string, mixed>
     */
    public function generate(string $toolName, array $rules, array $descriptions = []): array
    {
        $tree = [];

        foreach ($rules as $field => $fieldRules) {
            $this->insert($tree, explode('.', $field), $this->normalize($fieldRules));
        }

        $schema = $this->build($toolName, $tree, $descriptions, '');

        // The top level is always an object: a tool takes named arguments.
        return $schema['type'] === 'object'
            ? $schema
            : ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    /**
     * Split a rule specification into `[name, parameters]` pairs.
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private function normalize(mixed $fieldRules): array
    {
        $parts = match (true) {
            is_string($fieldRules) => explode('|', $fieldRules),
            is_array($fieldRules) => $fieldRules,
            default => [$fieldRules],
        };

        $normalized = [];

        foreach ($parts as $rule) {
            if ($rule instanceof EnumRule) {
                $cases = $this->enumCases($rule);

                if ($cases !== null) {
                    $normalized[] = ['in', $cases];

                    continue;
                }
            }

            // A closure or a non-stringable Rule object can only narrow what is
            // accepted, so it is runtime-only by construction.
            if ($rule instanceof Closure || (is_object($rule) && ! $rule instanceof Stringable)) {
                continue;
            }

            if (is_object($rule)) {
                $rule = (string) $rule;
            }

            if (! is_string($rule) || $rule === '') {
                continue;
            }

            [$name, $parameters] = str_contains($rule, ':')
                ? [strstr($rule, ':', true), explode(',', (string) substr((string) strstr($rule, ':'), 1))]
                : [$rule, []];

            /** @var string $name */
            $normalized[] = [strtolower($name), array_map(
                static fn (string $p): string => trim($p, " \t\n\r\0\x0B\"'"),
                $parameters,
            )];
        }

        return $normalized;
    }

    /**
     * Backed-enum case values behind `Rule::enum()`, or null if the rule's
     * shape is not what we expect and guessing would be wrong.
     *
     * @return list<string>|null
     */
    private function enumCases(EnumRule $rule): ?array
    {
        $reflection = new ReflectionClass($rule);

        if (! $reflection->hasProperty('type')) {
            return null;
        }

        $property = $reflection->getProperty('type');
        $type = $property->getValue($rule);

        if (! is_string($type) || ! enum_exists($type) || ! is_subclass_of($type, BackedEnum::class)) {
            return null;
        }

        return array_map(
            static fn (BackedEnum $case): string => (string) $case->value,
            $type::cases(),
        );
    }

    /**
     * @param array<string, mixed> $tree
     * @param list<string> $segments
     * @param list<array{0: string, 1: list<string>}> $rules
     */
    private function insert(array &$tree, array $segments, array $rules): void
    {
        $segment = array_shift($segments);

        if ($segment === null) {
            return;
        }

        $tree[$segment] ??= ['rules' => [], 'children' => []];

        if ($segments === []) {
            /** @var array{rules: list<array{0: string, 1: list<string>}>, children: array<string, mixed>} $node */
            $node = $tree[$segment];
            $node['rules'] = array_merge($node['rules'], $rules);
            $tree[$segment] = $node;

            return;
        }

        /** @var array{rules: list<array{0: string, 1: list<string>}>, children: array<string, mixed>} $node */
        $node = $tree[$segment];
        $this->insert($node['children'], $segments, $rules);
        $tree[$segment] = $node;
    }

    /**
     * @param array<string, mixed> $tree
     * @param array<string, string> $descriptions
     * @return array<string, mixed>
     */
    private function build(string $toolName, array $tree, array $descriptions, string $path): array
    {
        $properties = [];
        $required = [];

        foreach ($tree as $name => $node) {
            /** @var array{rules: list<array{0: string, 1: list<string>}>, children: array<string, mixed>} $node */
            $childPath = $path === '' ? (string) $name : $path.'.'.$name;

            if ($name === '*') {
                continue;
            }

            $properties[$name] = $this->buildNode($toolName, $node, $descriptions, $childPath);

            if ($this->isRequired($node['rules'])) {
                $required[] = (string) $name;
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
            // A model that invents an argument is a model we want to hear
            // about, not one we want to silently accommodate.
            'additionalProperties' => false,
        ];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @param array{rules: list<array{0: string, 1: list<string>}>, children: array<string, mixed>} $node
     * @param array<string, string> $descriptions
     * @return array<string, mixed>
     */
    private function buildNode(string $toolName, array $node, array $descriptions, string $path): array
    {
        $rules = $node['rules'];
        $children = $node['children'];
        $type = $this->resolveType($toolName, $path, $rules, $children);

        $schema = $type === null ? [] : ['type' => $type];

        if (isset($descriptions[$path])) {
            $schema['description'] = $descriptions[$path];
        }

        foreach ($rules as [$name, $parameters]) {
            if (in_array($name, self::FILE_RULES, true)) {
                throw UnsupportedValidationRule::make($toolName, $path, $name);
            }

            if (in_array($name, self::RUNTIME_ONLY, true)
                || in_array($name, ['required', 'nullable'], true)
                || isset(self::TYPES[$name])) {
                continue;
            }

            if (in_array($name, self::TYPE_DEPENDENT, true) && $type === null) {
                throw UnsupportedValidationRule::make($toolName, $path, $name.' (no type rule declared)');
            }

            $schema = $this->applyConstraint($toolName, $path, $schema, $type, $name, $parameters);
        }

        if ($this->isNullable($rules) && $type !== null) {
            $schema['type'] = [$type, 'null'];
        }

        if ($children !== []) {
            $schema = $this->applyChildren($toolName, $schema, $type, $children, $descriptions, $path);
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $children
     * @param array<string, string> $descriptions
     * @return array<string, mixed>
     */
    private function applyChildren(
        string $toolName,
        array $schema,
        ?string $type,
        array $children,
        array $descriptions,
        string $path,
    ): array {
        if (isset($children['*'])) {
            /** @var array{rules: list<array{0: string, 1: list<string>}>, children: array<string, mixed>} $wildcard */
            $wildcard = $children['*'];
            $schema['type'] = 'array';
            $schema['items'] = $this->buildNode($toolName, $wildcard, $descriptions, $path.'.*');

            return $schema;
        }

        $nested = $this->build($toolName, $children, $descriptions, $path);

        if ($type === 'array') {
            $schema['items'] = $nested;

            return $schema;
        }

        return array_merge($schema, $nested);
    }

    /**
     * @param list<array{0: string, 1: list<string>}> $rules
     * @param array<string, mixed> $children
     */
    private function resolveType(string $toolName, string $path, array $rules, array $children): ?string
    {
        foreach ($rules as [$name]) {
            if (isset(self::TYPES[$name])) {
                return self::TYPES[$name];
            }
        }

        foreach ($rules as [$name]) {
            if (in_array($name, ['email', 'url', 'uuid', 'ulid', 'date', 'date_format', 'regex',
                'alpha', 'alpha_num', 'alpha_dash', 'json', 'ip', 'ipv4', 'ipv6', 'timezone',
                'starts_with', 'ends_with', 'digits', 'digits_between'], true)) {
                return 'string';
            }
        }

        if (isset($children['*'])) {
            return 'array';
        }

        return $children === [] ? null : 'object';
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string> $parameters
     * @return array<string, mixed>
     */
    private function applyConstraint(
        string $toolName,
        string $path,
        array $schema,
        ?string $type,
        string $name,
        array $parameters,
    ): array {
        $numeric = $type === 'integer' || $type === 'number';

        return match ($name) {
            'in' => [...$schema, 'enum' => $parameters],
            'not_in' => [...$schema, 'not' => ['enum' => $parameters]],
            'email' => [...$schema, 'format' => 'email'],
            'url' => [...$schema, 'format' => 'uri'],
            'uuid' => [...$schema, 'format' => 'uuid'],
            'ulid' => [...$schema, 'pattern' => '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'],
            'date' => [...$schema, 'format' => 'date-time'],
            'date_format' => $schema,
            'regex' => [...$schema, 'pattern' => $this->pattern($parameters[0] ?? '')],
            'alpha' => [...$schema, 'pattern' => '^[A-Za-z]+$'],
            'alpha_num' => [...$schema, 'pattern' => '^[A-Za-z0-9]+$'],
            'alpha_dash' => [...$schema, 'pattern' => '^[A-Za-z0-9_-]+$'],
            'ip' => [...$schema, 'format' => 'ipv4'],
            'ipv4' => [...$schema, 'format' => 'ipv4'],
            'ipv6' => [...$schema, 'format' => 'ipv6'],
            'json' => [...$schema, 'contentMediaType' => 'application/json'],
            'timezone' => $schema,
            'starts_with' => [...$schema, 'pattern' => '^('.implode('|', array_map(
                static fn (string $p): string => preg_quote($p, '/'),
                $parameters,
            )).')'],
            'ends_with' => [...$schema, 'pattern' => '('.implode('|', array_map(
                static fn (string $p): string => preg_quote($p, '/'),
                $parameters,
            )).')$'],
            'digits' => [...$schema, 'pattern' => '^[0-9]{'.($parameters[0] ?? '1').'}$'],
            'digits_between' => [...$schema, 'pattern' => '^[0-9]{'.($parameters[0] ?? '1').','.($parameters[1] ?? '').'}$'],
            'distinct' => [...$schema, 'uniqueItems' => true],
            'min' => $this->bound($schema, $type, 'min', $parameters[0] ?? '0'),
            'max' => $this->bound($schema, $type, 'max', $parameters[0] ?? '0'),
            'size' => $this->size($schema, $type, $parameters[0] ?? '0'),
            'between' => $this->bound(
                $this->bound($schema, $type, 'min', $parameters[0] ?? '0'),
                $type,
                'max',
                $parameters[1] ?? '0',
            ),
            'gt', 'gte', 'lt', 'lte' => $numeric && is_numeric($parameters[0] ?? '')
                ? [...$schema, match ($name) {
                    'gt' => 'exclusiveMinimum',
                    'gte' => 'minimum',
                    'lt' => 'exclusiveMaximum',
                    default => 'maximum',
                } => $this->number($parameters[0])]
                // gt:other_field compares two fields; that is runtime-only.
                : $schema,
            default => throw UnsupportedValidationRule::make($toolName, $path, $name),
        };
    }

    /**
     * `min` means three different things depending on the type. Laravel knows
     * which from the other rules; so do we, because we required one.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function bound(array $schema, ?string $type, string $edge, string $value): array
    {
        $key = match ($type) {
            'integer', 'number' => $edge === 'min' ? 'minimum' : 'maximum',
            'array' => $edge === 'min' ? 'minItems' : 'maxItems',
            default => $edge === 'min' ? 'minLength' : 'maxLength',
        };

        $schema[$key] = $key === 'minimum' || $key === 'maximum'
            ? $this->number($value)
            : (int) $value;

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function size(array $schema, ?string $type, string $value): array
    {
        return match ($type) {
            'integer', 'number' => [...$schema, 'const' => $this->number($value)],
            'array' => [...$schema, 'minItems' => (int) $value, 'maxItems' => (int) $value],
            default => [...$schema, 'minLength' => (int) $value, 'maxLength' => (int) $value],
        };
    }

    private function number(string $value): int|float
    {
        return str_contains($value, '.') ? (float) $value : (int) $value;
    }

    /**
     * Laravel regex rules carry PHP delimiters and modifiers; JSON Schema
     * patterns carry neither.
     */
    private function pattern(string $regex): string
    {
        if ($regex === '') {
            return '';
        }

        $delimiter = $regex[0];
        $end = strrpos($regex, $delimiter);

        if ($end === false || $end === 0) {
            return $regex;
        }

        return substr($regex, 1, $end - 1);
    }

    /**
     * @param list<array{0: string, 1: list<string>}> $rules
     */
    private function isRequired(array $rules): bool
    {
        foreach ($rules as [$name]) {
            if ($name === 'required') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{0: string, 1: list<string>}> $rules
     */
    private function isNullable(array $rules): bool
    {
        foreach ($rules as [$name]) {
            if ($name === 'nullable') {
                return true;
            }
        }

        return false;
    }
}
