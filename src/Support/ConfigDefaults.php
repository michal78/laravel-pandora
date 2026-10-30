<?php

declare(strict_types=1);

namespace Pandora\Support;

/**
 * Fill a host's published config with the package defaults it predates.
 *
 * `mergeConfigFrom()` merges ONE level deep: a top-level key the host's file
 * has wins outright, nested arrays included. So a host that published
 * `config/pandora.php` before `features.channels` existed has a `features`
 * array without it, and the key is not "defaulted" -- it is missing, and the
 * feature behaves as switched off. Every release that adds a nested key would
 * break every host that ever published, silently.
 *
 * This merges recursively instead: the host's value wins wherever the host
 * set one, and a key the host's file does not have takes the package default
 * at any depth.
 *
 * Two kinds of value are NOT merged into, because the host owns the whole of
 * them and a default reappearing would be a change the host did not make:
 *
 *  - lists, which are values rather than sets of keys (the host's
 *    `context.providers` is its pipeline, in its order);
 *  - the collections in COLLECTIONS, whose keys name things the host chose to
 *    have. A host that deleted the `openai` connection meant it; merging it
 *    back would put a provider the host removed into the router and the
 *    health probe.
 *
 * @internal
 */
final class ConfigDefaults
{
    /**
     * Arrays whose entries the host owns outright. A host's value here
     * replaces the package's whole, exactly as `mergeConfigFrom()` would.
     */
    public const COLLECTIONS = [
        'providers.connections',
        'workspaces.roots',
        'memory.stores',
    ];

    /**
     * @param array<array-key, mixed> $defaults the package's config
     * @param array<array-key, mixed> $host what the application has already loaded
     * @return array<array-key, mixed>
     */
    public static function merge(array $defaults, array $host, string $path = ''): array
    {
        $merged = $defaults;

        foreach ($host as $key => $value) {
            $childPath = $path === '' ? (string) $key : "{$path}.{$key}";
            $default = $defaults[$key] ?? null;

            $merged[$key] = self::mergeable($default, $value, $childPath)
                ? self::merge($default, $value, $childPath)
                : $value;
        }

        return $merged;
    }

    /**
     * @phpstan-assert-if-true array<array-key, mixed> $default
     * @phpstan-assert-if-true array<array-key, mixed> $value
     */
    private static function mergeable(mixed $default, mixed $value, string $path): bool
    {
        // The host's side may be EMPTY as well as a map: where the package
        // has a map of settings, an empty array in a published file can only
        // mean the file predates every key in it. The package side may not:
        // an empty default says nothing about which keys belong there, so the
        // host's value stands -- which is also why the collections, where an
        // empty array can mean "none, deliberately", are excluded outright.
        return is_array($default) && is_array($value)
            && self::isMap($default) && ($value === [] || self::isMap($value))
            && ! in_array($path, self::COLLECTIONS, true);
    }

    /**
     * An array of named keys, as opposed to a list or an empty array.
     *
     * @param array<array-key, mixed> $array
     */
    private static function isMap(array $array): bool
    {
        return $array !== [] && ! array_is_list($array);
    }
}
