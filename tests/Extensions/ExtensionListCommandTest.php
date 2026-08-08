<?php

declare(strict_types=1);

use Pandora\Channels\ChannelRegistry;
use Pandora\Extensions\ExtensionDiscovery;
use Pandora\Tests\Fixtures\Channels\FixtureChannel;

/**
 * Phase 8, criterion 27 — the inventory, in a terminal.
 *
 * An operator without the control center still needs to answer "what is
 * installed and what did it actually register". The mismatch lines are the
 * reason the command is worth having over `composer show`.
 */
beforeEach(function (): void {
    $path = sys_get_temp_dir().'/pandora-installed-'.uniqid().'.json';

    file_put_contents($path, json_encode(['packages' => [[
        'name' => 'vendor/fixture',
        'version' => '2.1.0',
        'autoload' => ['psr-4' => ['Pandora\\Tests\\Fixtures\\' => 'tests/Fixtures/']],
        'extra' => ['pandora' => [
            'name' => 'Fixture extension',
            'description' => 'Stands in for a real one.',
            'provides' => ['channels' => ['fixture', 'imaginary']],
            'documentation' => 'https://example.test/docs',
        ]],
    ]]], JSON_THROW_ON_ERROR));

    config()->set('pandora.extensions.installed_json', $path);
    app()->forgetInstance(ExtensionDiscovery::class);

    app(ChannelRegistry::class)->register(new FixtureChannel);
});

it('lists an installed extension with its manifest', function (): void {
    $this->artisan('pandora:extension:list')
        ->expectsOutputToContain('Fixture extension')
        ->expectsOutputToContain('vendor/fixture')
        ->expectsOutputToContain('Stands in for a real one.')
        ->expectsOutputToContain('https://example.test/docs')
        ->assertSuccessful();
});

it('names what was declared and never registered', function (): void {
    $this->artisan('pandora:extension:list')
        ->expectsOutputToContain('declared, not registered')
        ->expectsOutputToContain('imaginary')
        ->assertSuccessful();
});

it('can show only the extensions that disagree with their manifest', function (): void {
    $this->artisan('pandora:extension:list --mismatched')
        ->expectsOutputToContain('Fixture extension')
        ->assertSuccessful();
});

it('says so plainly when nothing is installed', function (): void {
    config()->set('pandora.extensions.installed_json', '/nonexistent/installed.json');
    app()->forgetInstance(ExtensionDiscovery::class);

    $this->artisan('pandora:extension:list')
        ->expectsOutputToContain('No installed package declares')
        ->assertSuccessful();
});
