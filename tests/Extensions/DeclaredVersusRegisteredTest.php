<?php

declare(strict_types=1);

use Pandora\Channels\ChannelRegistry;
use Pandora\Extensions\ExtensionDiscovery;
use Pandora\Extensions\ExtensionInspector;
use Pandora\Tests\Fixtures\Channels\FixtureChannel;
use Pandora\Tools\ToolRegistry;

/**
 * Phase 8, criterion 25 — a manifest describes, and never grants.
 *
 * Two failures are possible and they are opposites. A package can declare a
 * capability it does not register: the capability simply does not exist, because
 * the registry is the authority and a JSON file is not. And a package can
 * register something it never declared, which nothing here prevents — because
 * withholding it would mean a manifest could disable its own package's code,
 * and an author who wants that can decline to write the code.
 *
 * So the inspector reports both and enforces neither. The innocent cause of a
 * difference and the interesting one look identical from here.
 */
function inspectorFor(array $packages, callable $register): ExtensionInspector
{
    $path = sys_get_temp_dir().'/pandora-installed-'.uniqid().'.json';
    file_put_contents($path, json_encode(['packages' => $packages], JSON_THROW_ON_ERROR));

    $channels = app(ChannelRegistry::class);
    $tools = app(ToolRegistry::class)->flush();

    $register($channels, $tools);

    return new ExtensionInspector(new ExtensionDiscovery($path), $channels, $tools);
}

function fixturePackage(array $provides): array
{
    return [
        'name' => 'vendor/fixture',
        'version' => '1.0.0',
        'autoload' => ['psr-4' => ['Pandora\\Tests\\Fixtures\\' => 'tests/Fixtures/']],
        'extra' => ['pandora' => ['name' => 'Fixture', 'provides' => $provides]],
    ];
}

it('reports an extension that registers exactly what it declares', function (): void {
    $inspector = inspectorFor(
        [fixturePackage(['channels' => ['fixture']])],
        static fn (ChannelRegistry $channels) => $channels->register(new FixtureChannel),
    );

    $extensions = $inspector->all();

    expect($extensions)->toHaveCount(1)
        ->and($extensions[0]->matchesItsManifest())->toBeTrue()
        ->and($extensions[0]->registered['channels'] ?? [])->toBe(['fixture']);
});

it('grants nothing for a channel a package declares but never registers', function (): void {
    $inspector = inspectorFor(
        [fixturePackage(['channels' => ['fixture', 'imaginary']])],
        static fn (ChannelRegistry $channels) => $channels->register(new FixtureChannel),
    );

    $extension = $inspector->all()[0];

    expect($extension->missing['channels'] ?? [])->toBe(['imaginary'])
        ->and($extension->matchesItsManifest())->toBeFalse()
        // And the declared-but-absent channel is genuinely absent: an account
        // pointed at it would find no adapter.
        ->and(app(ChannelRegistry::class)->get('imaginary'))->toBeNull();
});

it('shows a channel a package registers without declaring it', function (): void {
    $inspector = inspectorFor(
        [fixturePackage(['channels' => ['fixture']])],
        static function (ChannelRegistry $channels): void {
            $channels->register(new FixtureChannel);
            $channels->register(new FixtureChannel('undeclared'));
        },
    );

    $extension = $inspector->all()[0];

    // Reported, not blocked. A package doing more than its manifest admits is
    // worth a human looking at it, and a manifest is not a permission system.
    expect($extension->undeclared['channels'] ?? [])->toBe(['undeclared'])
        ->and($extension->matchesItsManifest())->toBeFalse();
});

it('attributes nothing to a package whose namespace does not match', function (): void {
    $inspector = inspectorFor(
        [[
            'name' => 'vendor/elsewhere',
            'version' => '1.0.0',
            'autoload' => ['psr-4' => ['Somebody\\Else\\' => 'src/']],
            'extra' => ['pandora' => ['name' => 'Elsewhere', 'provides' => ['channels' => ['fixture']]]],
        ]],
        static fn (ChannelRegistry $channels) => $channels->register(new FixtureChannel),
    );

    $extension = $inspector->all()[0];

    expect($extension->registered)->toBe([])
        ->and($extension->missing['channels'] ?? [])->toBe(['fixture']);
});
