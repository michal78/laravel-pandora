<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Pandora\Pandora\Providers\Catalog\ModelCatalog;
use Pandora\Pandora\Providers\Credentials\CredentialManager;
use Pandora\Pandora\Providers\Health\ProviderHealthMonitor;
use Pandora\Pandora\Providers\ProviderManager;
use Pandora\Pandora\UI\Livewire\ProvidersIndex;

/**
 * Phase 3 acceptance criterion 36 -- the page shows configuration and health,
 * and never a credential value.
 *
 * Two levels: anyone with access may see which providers exist and whether
 * they are answering, because that is what somebody debugging a broken chat
 * needs. Credentials and prices need `pandora.providers.manage`.
 */
beforeEach(function (): void {
    config()->set('pandora.providers.connections', [
        'fake' => ['adapter' => 'fake'],
        'openai' => [
            'adapter' => 'openai-compatible',
            'base_url' => 'https://api.openai.test/v1',
            'api_key' => 'sk-a-very-secret-value',
        ],
    ]);

    app()->forgetInstance(ProviderManager::class);

    Gate::define('pandora.access', static fn (): bool => true);
    Gate::define('pandora.providers.manage', static fn (): bool => true);
});

it('renders every configured connection for an authorized user', function (): void {
    $this->actingAsUser();

    Livewire::test(ProvidersIndex::class)
        ->assertOk()
        ->assertSee('openai')
        ->assertSee('openai-compatible')
        ->assertSee('https://api.openai.test/v1');
});

it('denies a user without pandora.access', function (): void {
    Gate::define('pandora.access', static fn (): bool => false);
    $this->actingAsUser();

    Livewire::test(ProvidersIndex::class)->assertForbidden();
});

it('shows health to an ordinary user, without credentials or prices', function (): void {
    // Somebody debugging a broken chat needs to know whether the provider is
    // answering. They do not need to know what it costs.
    Gate::define('pandora.providers.manage', static fn (): bool => false);

    config()->set('pandora.providers.health.failure_threshold', 1);
    app(ProviderHealthMonitor::class)->recordFailure('openai', 'Connection refused');

    app(ModelCatalog::class)->seedFromConfig([[
        'provider' => 'openai',
        'key' => 'gpt-4o-mini',
        'input_price' => 0.15,
        'output_price' => 0.60,
        'pricing_source' => 'https://example.test/pricing',
        'pricing_date' => now()->toDateString(),
    ]]);

    $credential = app(CredentialManager::class)->issue('openai', 'sk-stored-and-secret');

    $this->actingAsUser();

    $rendered = Livewire::test(ProvidersIndex::class)
        ->assertOk()
        ->assertSee('openai')
        ->assertSee('Degraded')
        ->assertSee('gpt-4o-mini')
        ->html();

    expect($rendered)->not->toContain($credential->fingerprint)
        ->and($rendered)->not->toContain('0.15')
        ->and($rendered)->not->toContain('sk-stored-and-secret');
});

it('never renders a credential value', function (): void {
    $this->actingAsUser();

    app(CredentialManager::class)->issue('fake', 'sk-stored-and-secret');

    $rendered = Livewire::test(ProvidersIndex::class)->html();

    expect($rendered)->not->toContain('sk-a-very-secret-value')
        ->not->toContain('sk-stored-and-secret')
        // Presence is the question an operator is actually asking.
        ->and($rendered)->toContain('Configured');
});

it('shows a fingerprint, which identifies a key without revealing it', function (): void {
    $this->actingAsUser();

    $credential = app(CredentialManager::class)->issue('fake', 'sk-stored-and-secret');

    Livewire::test(ProvidersIndex::class)->assertSee($credential->fingerprint);
});

it('says plainly when a provider has no credential', function (): void {
    config()->set('pandora.providers.connections', [
        'openai' => ['adapter' => 'openai-compatible', 'base_url' => 'https://api.openai.test/v1'],
    ]);
    app()->forgetInstance(ProviderManager::class);

    $this->actingAsUser();

    Livewire::test(ProvidersIndex::class)->assertSee('None');
});

it('shows health, including the reason a provider is degraded', function (): void {
    config()->set('pandora.providers.health.failure_threshold', 1);
    app(ProviderHealthMonitor::class)->recordFailure('openai', 'Connection refused by the gateway');

    $this->actingAsUser();

    Livewire::test(ProvidersIndex::class)
        ->assertSee('Degraded')
        ->assertSee('Connection refused by the gateway');
});

it('lists the catalog for each provider, and flags stale pricing', function (): void {
    config()->set('pandora.models.pricing_stale_after_days', 30);

    app(ModelCatalog::class)->seedFromConfig([[
        'provider' => 'openai',
        'key' => 'gpt-4o-mini',
        'context_limit' => 128_000,
        'capabilities' => ['tools', 'vision'],
        'input_price' => 0.15,
        'output_price' => 0.60,
        'pricing_source' => 'https://example.test/pricing',
        'pricing_date' => now()->subDays(200)->toDateString(),
    ]]);

    $this->actingAsUser();

    Livewire::test(ProvidersIndex::class)
        ->assertSee('gpt-4o-mini')
        ->assertSee('128,000')
        // Loud, because a stale price produces a cost report that looks
        // authoritative and is wrong.
        ->assertSee('Stale')
        ->assertSee('Pricing needs review');
});

it('says a model is unpriced rather than implying it is free', function (): void {
    app(ModelCatalog::class)->seedFromConfig([
        ['provider' => 'fake', 'key' => 'fake-model'],
    ]);

    $this->actingAsUser();

    Livewire::test(ProvidersIndex::class)->assertSee('unpriced');
});
