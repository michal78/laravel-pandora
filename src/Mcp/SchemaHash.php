<?php

declare(strict_types=1);

namespace Pandora\Mcp;

/**
 * What a tool looked like when somebody approved it.
 *
 * Canonical JSON over the remote name, the namespaced name, the description
 * and the input schema, with object keys sorted at every depth, hashed with
 * SHA-256.
 *
 * **The description is in the hash, and that is the whole point.** Hashing
 * only the input schema is the version of this that looks correct and is
 * wrong: it catches a server that adds a `path` parameter and it misses a
 * server that keeps every parameter identical and rewrites its description
 * into an instruction. The second is the easier attack, it needs no schema
 * change at all, and there is no other place we would notice it — the
 * description is the field we were going to treat as documentation and paste
 * into a prompt (ADR-0014).
 *
 * Keys are sorted so that a server re-serialising the same tool in a different
 * order does not read as a change. That is not politeness: an approval that
 * cleared itself on every discovery would be an approval nobody could keep,
 * and the pressure to add "ignore small changes" starts the moment it happens
 * twice.
 */
final class SchemaHash
{
    /**
     * @param array<string, mixed>|null $inputSchema
     */
    public static function of(
        string $remoteName,
        string $namespacedName,
        ?string $description,
        ?array $inputSchema,
    ): string {
        $canonical = json_encode(
            [
                // Fixed order, because this array's own key order is part of
                // the input and an associative array is not sorted by `canon`
                // at the top level -- it is built here, in one place.
                'remote_name' => $remoteName,
                'namespaced_name' => $namespacedName,
                'description' => $description ?? '',
                'input_schema' => self::canonical($inputSchema ?? []),
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return hash('sha256', $canonical);
    }

    public static function ofTool(McpTool $tool): string
    {
        return self::of(
            $tool->remote_name,
            $tool->namespaced_name,
            $tool->description,
            $tool->input_schema,
        );
    }

    /**
     * Sort every object key, at every depth, leaving list order alone.
     *
     * A list's order is meaningful — `required: [a, b]` and `required: [b, a]`
     * describe the same constraint, but `enum` order can reach a model as
     * preference, and re-ordering something we were about to hash is how a
     * canonicaliser starts deciding what counts as a change.
     *
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function canonical(array $value): array
    {
        $isList = array_is_list($value);

        $mapped = array_map(
            static fn (mixed $item): mixed => is_array($item) ? self::canonical($item) : $item,
            $value,
        );

        if (! $isList) {
            ksort($mapped);
        }

        return $mapped;
    }
}
