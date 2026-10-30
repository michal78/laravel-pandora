<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

use function Orchestra\Testbench\default_migration_path;

use Pandora\Support\ConfigDefaults;

/**
 * Phase 9, criterion 25 -- a published config from an earlier version still
 * boots, and a key added since gets the package default rather than going
 * missing.
 *
 * The boots are real: a file is written where a host's published config
 * lives, and the application is rebuilt around it, so this tests the path a
 * host's request takes rather than the merge function on its own.
 */

/**
 * The package config as loaded by PHP, `env()` and all.
 *
 * @return array<string, mixed>
 */
function packageConfig(): array
{
    /** @var array<string, mixed> $config */
    $config = require dirname(__DIR__, 2).'/config/pandora.php';

    return $config;
}

/**
 * Boot the application with this file as the host's published config.
 */
function bootWithPublishedConfig(string $source): void
{
    File::put(config_path('pandora.php'), $source);

    test()->refreshApplication();

    // A rebuilt application on SQLite `:memory:` is a new, empty database,
    // and the harness rolls Pandora's migrations back when it tears down.
    // Give it the schema the harness would have built.
    if (config('database.connections.'.config('database.default').'.database') === ':memory:') {
        foreach ([default_migration_path(), dirname(__DIR__, 2).'/database/migrations'] as $path) {
            Artisan::call('migrate', ['--path' => $path, '--realpath' => true, '--force' => true]);
        }
    }
}

/**
 * A config file's source with one `'key' => ...,` line removed, as an older
 * release that predates the key would have it.
 */
function withoutLine(string $source, string $needle): string
{
    $lines = explode("\n", $source);

    $kept = array_filter($lines, static fn (string $line): bool => ! str_contains($line, $needle));

    expect(count($kept))->toBe(count($lines) - 1, "expected exactly one line containing {$needle}");

    return implode("\n", $kept);
}

afterEach(function (): void {
    // A published config left behind would shadow the package's for every
    // test that follows -- the exact thing NoShadowConfigTest exists to fail.
    File::delete(config_path('pandora.php'));
});

it('boots on the config v0.1.0 published, verbatim', function (): void {
    bootWithPublishedConfig(File::get(dirname(__DIR__).'/Fixtures/Upgrade/v0.1.0/pandora.php'));

    expect(config('pandora.features.channels'))->toBeTrue()
        ->and(app(Pandora\Pandora::class))->toBeInstanceOf(Pandora\Pandora::class);
});

it('gives a nested key the host config predates its package default', function (): void {
    // `features.channels` and the `channels.view` ability both arrived after
    // Phase 7, which is exactly when the skeleton's stale copy hid them.
    $source = File::get(dirname(__DIR__, 2).'/config/pandora.php');
    $source = withoutLine($source, "'channels' => env('PANDORA_FEATURE_CHANNELS'");
    $source = withoutLine($source, "'channels.view' => 'pandora.channels.view'");

    bootWithPublishedConfig($source);

    expect(config('pandora.features'))->toHaveKey('channels')
        ->and(config('pandora.features')['channels'])->toBeTrue()
        ->and(config('pandora.abilities')['channels.view'] ?? null)->toBe('pandora.channels.view');
});

it('keeps every value the host did set', function (): void {
    $source = str_replace(
        "'brand' => env('PANDORA_UI_BRAND', 'Pandora'),",
        "'brand' => 'Acme Agents',",
        File::get(dirname(__DIR__, 2).'/config/pandora.php'),
        $count,
    );

    expect($count)->toBe(1);

    bootWithPublishedConfig($source);

    expect(config('pandora.ui.brand'))->toBe('Acme Agents');
});

it('does not bring back a provider connection the host removed', function (): void {
    // Connections are a set the host owns. A default merged back in would put
    // a provider the host deleted into the router and the health probe.
    $source = File::get(dirname(__DIR__, 2).'/config/pandora.php');
    $source = preg_replace("/\n            'openrouter' => \[.*?\n            \],/s", '', $source, 1, $count);

    expect($count)->toBe(1);

    bootWithPublishedConfig((string) $source);

    expect(config('pandora.providers.connections'))->not->toHaveKey('openrouter')
        ->and(config('pandora.providers.connections'))->toHaveKey('anthropic');
});

it('fills every nested key, at any depth, from the package', function (): void {
    // The generic form of the two tests above, over the merge itself: for
    // every leaf of the package config outside a host-owned collection, a host
    // config lacking just that leaf ends up with the package's value.
    $package = packageConfig();

    $leaves = (function (array $config, array $prefix = []) use (&$leaves): array {
        $found = [];

        foreach ($config as $key => $value) {
            $path = [...$prefix, (string) $key];

            if (in_array(implode('.', $path), ConfigDefaults::COLLECTIONS, true)) {
                continue;
            }

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $found = [...$found, ...$leaves($value, $path)];

                continue;
            }

            if (count($path) > 1) {
                $found[] = $path;
            }
        }

        return $found;
    });

    $checked = 0;

    foreach ($leaves($package) as $path) {
        $host = $package;
        $cursor = &$host;

        foreach (array_slice($path, 0, -1) as $segment) {
            $cursor = &$cursor[$segment];
        }

        unset($cursor[end($path)], $cursor);

        $merged = ConfigDefaults::merge($package, $host);

        $value = $merged;

        // array_key_exists rather than toHaveKey, which reads a dot as
        // nesting -- and `abilities.prompts.view` is one key.
        foreach ($path as $segment) {
            expect(is_array($value) && array_key_exists($segment, $value))
                ->toBeTrue(implode(' → ', $path).' went missing');
            $value = $value[$segment];
        }

        $checked++;
    }

    // A guard on the guard: a walk that found nothing would pass vacuously.
    expect($checked)->toBeGreaterThan(100);
});

it('treats a list as the host\'s value, not a set to merge into', function (): void {
    $merged = ConfigDefaults::merge(
        ['context' => ['providers' => ['A', 'B', 'C']]],
        ['context' => ['providers' => ['C', 'A']]],
    );

    expect($merged['context']['providers'])->toBe(['C', 'A']);
});
