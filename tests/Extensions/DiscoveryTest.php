<?php

declare(strict_types=1);

use Pandora\Extensions\ExtensionDiscovery;
use Pandora\Extensions\ExtensionManifest;

/**
 * Phase 8, criterion 23 — inspecting an extension never executes it.
 *
 * The load-bearing test is the third one. A package whose classes do not exist,
 * whose autoload prefix points at nothing, and which would fatal the instant
 * anything tried to boot it, still appears on the page with its name and its
 * description. That is not a nice-to-have: the extension an operator most needs
 * to look at is the broken one, and a page that boots every candidate in order
 * to render a table cannot show it to them (ADR-0016).
 *
 * Everything here reads one local JSON file. There is no code path in this
 * class that fetches anything.
 */
function writeInstalledJson(array $packages): string
{
    $path = sys_get_temp_dir().'/pandora-installed-'.uniqid().'.json';

    file_put_contents($path, json_encode(['packages' => $packages], JSON_THROW_ON_ERROR));

    return $path;
}

function discoveryFor(array $packages): ExtensionDiscovery
{
    return new ExtensionDiscovery(writeInstalledJson($packages));
}

it('finds packages that declare an extra.pandora block', function (): void {
    $discovery = discoveryFor([
        [
            'name' => 'vendor/slack',
            'version' => '1.2.0',
            'extra' => ['pandora' => [
                'name' => 'Slack',
                'description' => 'Slack as a Pandora channel.',
                'provides' => ['channels' => ['slack']],
                'documentation' => 'https://example.test/docs',
            ]],
        ],
        ['name' => 'symfony/uid', 'version' => '7.0.0'],
        ['name' => 'vendor/other', 'version' => '1.0.0', 'extra' => ['laravel' => []]],
    ]);

    $manifests = $discovery->manifests();

    expect($manifests)->toHaveCount(1)
        ->and($manifests[0]->package)->toBe('vendor/slack')
        ->and($manifests[0]->name)->toBe('Slack')
        ->and($manifests[0]->version)->toBe('1.2.0')
        ->and($manifests[0]->declares('channels'))->toBe(['slack']);
});

it('renders a package whose classes do not exist', function (): void {
    $discovery = discoveryFor([[
        'name' => 'vendor/broken',
        'version' => '0.1.0',
        'autoload' => ['psr-4' => ['Vendor\\Broken\\' => 'src/']],
        'extra' => ['pandora' => [
            'name' => 'Broken extension',
            'description' => 'Its service provider references a class that was deleted.',
            'provides' => ['channels' => ['broken']],
        ]],
    ]]);

    $manifests = $discovery->manifests();

    // No autoloading happened, so nothing had the chance to fatal.
    expect(class_exists('Vendor\\Broken\\BrokenChannel'))->toBeFalse()
        ->and($manifests)->toHaveCount(1)
        ->and($manifests[0]->name)->toBe('Broken extension');
});

it('reads a Composer 1 style bare array too', function (): void {
    $path = sys_get_temp_dir().'/pandora-installed-'.uniqid().'.json';

    file_put_contents($path, json_encode([[
        'name' => 'vendor/old',
        'version' => '1.0.0',
        'extra' => ['pandora' => ['name' => 'Old']],
    ]], JSON_THROW_ON_ERROR));

    expect((new ExtensionDiscovery($path))->manifests())->toHaveCount(1);
});

it('reports nothing rather than failing when the file is absent', function (): void {
    // A package suite under Testbench frequently has no vendor directory of its
    // own. "No extensions installed" is the honest answer, not an exception.
    expect((new ExtensionDiscovery('/nonexistent/installed.json'))->manifests())->toBe([]);
});

it('reports nothing rather than failing when the file is not JSON', function (): void {
    $path = sys_get_temp_dir().'/pandora-installed-'.uniqid().'.json';
    file_put_contents($path, 'not json at all {{{');

    expect((new ExtensionDiscovery($path))->manifests())->toBe([]);
});

it('attributes a class to its package by the longest matching prefix', function (): void {
    $discovery = discoveryFor([
        [
            'name' => 'vendor/base',
            'version' => '1.0.0',
            'autoload' => ['psr-4' => ['Vendor\\' => 'src/']],
            'extra' => ['pandora' => ['name' => 'Base']],
        ],
        [
            'name' => 'vendor/slack',
            'version' => '1.0.0',
            'autoload' => ['psr-4' => ['Vendor\\Slack\\' => 'src/']],
            'extra' => ['pandora' => ['name' => 'Slack']],
        ],
    ]);

    expect($discovery->packageFor('Vendor\\Slack\\SlackChannel'))->toBe('vendor/slack')
        ->and($discovery->packageFor('Vendor\\Other\\Thing'))->toBe('vendor/base')
        ->and($discovery->packageFor('App\\Channels\\Mine'))->toBeNull();
});

it('ignores a manifest that is not an object', function (): void {
    expect(ExtensionManifest::fromPackage([
        'name' => 'vendor/weird',
        'extra' => ['pandora' => 'yes please'],
    ]))->toBeNull();
});
