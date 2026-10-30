<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * Phase 9, criterion 18 -- a published config in the Testbench skeleton fails
 * the run rather than shadowing the package's.
 *
 * The suite boots a Laravel skeleton inside vendor/, and anything published
 * into it stays there between runs. A `config/pandora.php` left by an install
 * or a `vendor:publish` is then what the suite tests instead of the package's
 * own config, and nothing fails: `mergeConfigFrom()` merges one level deep, so
 * the stale file's top-level arrays replace the package's outright and a newly
 * added key simply does not exist. It happened four times and was diagnosed
 * once, each time presenting as "my new flag does nothing".
 *
 * `InstallationTest` deletes what it publishes. This catches everything else
 * -- a hand-run `vendor:publish`, a test that forgot, a future installer.
 */

/**
 * Every leaf key path in a config array, as segments.
 *
 * Segments rather than a dotted string, because some keys contain dots
 * themselves -- `abilities.prompts.view` is one key under `abilities`, and a
 * dotted lookup would go looking for `prompts` instead. A list is a leaf: its
 * items are values, not keys a shadow could delete.
 *
 * @param array<array-key, mixed> $config
 * @param list<string> $prefix
 * @return list<list<string>>
 */
function configLeafPaths(array $config, array $prefix = []): array
{
    $paths = [];

    foreach ($config as $key => $value) {
        $path = [...$prefix, (string) $key];

        if (is_array($value) && $value !== [] && ! array_is_list($value)) {
            $paths = [...$paths, ...configLeafPaths($value, $path)];

            continue;
        }

        $paths[] = $path;
    }

    return $paths;
}

/**
 * @param array<array-key, mixed> $config
 * @param list<string> $path
 */
function configHasPath(array $config, array $path): bool
{
    foreach ($path as $segment) {
        if (! is_array($config) || ! array_key_exists($segment, $config)) {
            return false;
        }

        $config = $config[$segment];
    }

    return true;
}

it('runs against the package config, not a copy published into the skeleton', function (): void {
    expect(File::exists(config_path('pandora.php')))->toBeFalse(
        'A published config/pandora.php is in the Testbench skeleton at '.config_path('pandora.php')
        .'. The suite is testing that snapshot instead of config/pandora.php. Delete it.',
    );
});

it('sees every key the package config declares', function (): void {
    // The symptom rather than the cause, so a shadow arriving by some other
    // route than that one file still fails here.
    /** @var array<string, mixed> $package */
    $package = require dirname(__DIR__, 2).'/config/pandora.php';

    /** @var array<string, mixed> $loaded */
    $loaded = config('pandora');

    $missing = array_values(array_map(
        static fn (array $path): string => implode(' → ', $path),
        array_filter(
            configLeafPaths($package),
            static fn (array $path): bool => ! configHasPath($loaded, $path),
        ),
    ));

    expect($missing)->toBe([]);
});
