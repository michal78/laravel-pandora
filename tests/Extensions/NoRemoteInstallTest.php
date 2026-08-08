<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Route;
use Pandora\Extensions\ExtensionDiscovery;
use Pandora\Extensions\ExtensionInspector;

/**
 * Phase 8, criterion 26 — Pandora inspects extensions and acquires none.
 *
 * This is the criterion for something that is absent, so it is asserted
 * structurally rather than by driving a feature. There is no marketplace, no
 * remote install, no update check and no allowlist of registries that would make
 * one acceptable. Excluded, not deferred: a UI that can install code is a UI
 * whose authorization bug is arbitrary execution, and the entire surface would
 * exist to save somebody a `composer require` (ADR-0016).
 *
 * The tests are written to fail if that changes quietly. Adding the feature
 * means deleting an assertion, which is a diff a reviewer sees.
 */
it('registers no route that could fetch or install a package', function (): void {
    foreach (Route::getRoutes() as $route) {
        expect($route->uri())->not->toMatch('/extension.*(install|update|download|fetch|upgrade)/i')
            ->and((string) $route->getName())->not->toMatch('/extension.*(install|update|download|fetch|upgrade)/i');
    }
});

it('exposes only reads on the extension surface', function (): void {
    $public = static fn (string $class): array => array_values(array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        array_filter(
            (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $m): bool => ! $m->isConstructor(),
        ),
    ));

    expect($public(ExtensionDiscovery::class))->toBe(['manifests', 'packageFor', 'forget'])
        ->and($public(ExtensionInspector::class))->toBe(['all']);
});

it('contains no code that reaches the network or writes a file', function (): void {
    $files = glob(__DIR__.'/../../src/Extensions/*.php') ?: [];

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);

        expect($source)
            ->not->toMatch('/\bcurl_\w+\s*\(/')
            ->not->toMatch('/\bfile_put_contents\s*\(/')
            ->not->toMatch('/\b(exec|shell_exec|proc_open|passthru|system|popen)\s*\(/')
            ->not->toMatch('/\bHttp::/')
            ->not->toMatch('/\bfopen\s*\(/')
            ->not->toMatch('/\b(unlink|rename|copy|mkdir)\s*\(/');
    }
});

it('offers no console command that installs an extension', function (): void {
    $commands = array_keys(app(Kernel::class)->all());

    foreach ($commands as $command) {
        expect($command)->not->toMatch('/^pandora:extension:(install|update|remove|search|add)$/');
    }

    // The one that does exist reads.
    expect($commands)->toContain('pandora:extension:list');
});
