<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Pandora\Channels\ChannelRegistry;
use Pandora\Extensions\ExtensionDiscovery;
use Pandora\Tests\Fixtures\Channels\FixtureChannel;
use Pandora\UI\Livewire\ExtensionsIndex;

/**
 * Phase 8 — the Extensions page.
 *
 * An inventory, and only an inventory. The assertions worth reading are the
 * last three: the page renders a package that could not be booted, it shows the
 * difference between what a manifest claims and what the registries hold, and
 * it offers nothing that installs anything.
 */
beforeEach(function (): void {
    Gate::define('pandora.access', static fn (): bool => true);
    Gate::define('pandora.extensions.view', static fn (): bool => true);

    $this->actingAsUser();

    $path = sys_get_temp_dir().'/pandora-installed-'.uniqid().'.json';

    file_put_contents($path, json_encode(['packages' => [
        [
            'name' => 'vendor/fixture',
            'version' => '2.1.0',
            'autoload' => ['psr-4' => ['Pandora\\Tests\\Fixtures\\' => 'tests/Fixtures/']],
            'extra' => ['pandora' => [
                'name' => 'Fixture extension',
                'description' => 'Stands in for a real one.',
                'provides' => ['channels' => ['fixture', 'imaginary']],
            ]],
        ],
        [
            'name' => 'vendor/broken',
            'version' => '0.1.0',
            'autoload' => ['psr-4' => ['Vendor\\Broken\\' => 'src/']],
            'extra' => ['pandora' => [
                'name' => 'Broken extension',
                'description' => 'Its classes were deleted.',
            ]],
        ],
    ]], JSON_THROW_ON_ERROR));

    config()->set('pandora.extensions.installed_json', $path);
    app()->forgetInstance(ExtensionDiscovery::class);

    app(ChannelRegistry::class)->register(new FixtureChannel);
});

it('lists what composer installed', function (): void {
    Livewire::test(ExtensionsIndex::class)
        ->assertOk()
        ->assertSee('Fixture extension')
        ->assertSee('vendor/fixture')
        ->assertSee('Stands in for a real one.');
});

it('renders an extension that could not be booted', function (): void {
    Livewire::test(ExtensionsIndex::class)
        ->assertSee('Broken extension')
        ->assertSee('Its classes were deleted.');

    // Nothing autoloaded it, which is why the page rendered at all.
    expect(class_exists('Vendor\\Broken\\Anything'))->toBeFalse();
});

it('shows what a manifest claimed and never delivered', function (): void {
    Livewire::test(ExtensionsIndex::class)
        ->assertSee('Declared, not registered')
        ->assertSee('imaginary');
});

it('offers nothing that installs anything', function (): void {
    $html = Livewire::test(ExtensionsIndex::class)->html();

    foreach (['wire:click="install', 'wire:click="update', 'wire:click="upgrade', 'Install extension'] as $needle) {
        expect($html)->not->toContain($needle);
    }

    // Declared on the component itself, ignoring what Livewire's base class
    // brings: `mount` and `render` and nothing else. Adding a write here means
    // deleting this assertion, which is a diff a reviewer sees.
    $declared = array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        array_filter(
            (new ReflectionClass(ExtensionsIndex::class))->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === ExtensionsIndex::class,
        ),
    );

    expect(array_values($declared))->toBe(['mount', 'render']);
});

it('requires an ability that is absent by default', function (): void {
    Gate::define('pandora.extensions.view', static fn (): bool => false);

    Livewire::test(ExtensionsIndex::class)->assertForbidden();
});

it('is withheld entirely by the feature flag', function (): void {
    config()->set('pandora.features.extensions', false);

    Livewire::test(ExtensionsIndex::class)->assertNotFound();
});
