<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Pandora\Providers\Catalog\ModelCatalog;
use Pandora\Providers\Credentials\CredentialManager;
use Pandora\Providers\Health\ProviderHealthMonitor;
use Pandora\Providers\ProviderManager;
use Pandora\UI\Livewire\ProvidersIndex;

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

/**
 * Whether one provider's disclosure is rendered open.
 *
 * Matched on the `<details>` tag for that provider rather than on the word
 * "open" appearing somewhere in the page, which is true of almost any page.
 */
function isOpen(string $html, string $provider): bool
{
    $matched = preg_match(
        '/<details[^>]*wire:key="provider-'.preg_quote($provider, '/').'"[^>]*>/',
        $html,
        $matches,
    );

    expect($matched)->toBe(1, "no disclosure was rendered for [{$provider}]");

    return str_contains($matches[0], ' open');
}

it('collapses each provider, and the closed row still answers the question', function (): void {
    // The page was one long scroll because every connection rendered its whole
    // model catalogue whether or not anybody was looking at it.
    //
    // Collapsing only helps if the summary is a complete answer. A page that
    // hides whether a credential is installed until you open the row has moved
    // the problem, not solved it — so the closed state is asserted here, not
    // just the presence of a disclosure.
    app(CredentialManager::class)->issue('openai', 'sk-live-value');

    app(ModelCatalog::class)->seedFromConfig([[
        'provider' => 'openai',
        'key' => 'gpt-4o-mini',
        'context_limit' => 128_000,
        'capabilities' => ['tools'],
        'input_price' => 0.15,
        'output_price' => 0.60,
        'pricing_source' => 'https://example.test/pricing',
        'pricing_date' => now()->toDateString(),
    ]]);

    $this->actingAsUser();

    $html = Livewire::test(ProvidersIndex::class)->assertOk()->html();

    expect($html)->toContain('pd-provider-summary')
        ->and($html)->toContain('Key installed')
        ->and($html)->toContain('1 model');
});

it('leaves a healthy, credentialled, freshly priced provider shut', function (): void {
    config()->set('pandora.providers.connections', [
        'openai' => ['adapter' => 'openai-compatible', 'base_url' => 'https://api.openai.test/v1'],
    ]);
    app()->forgetInstance(ProviderManager::class);

    app(CredentialManager::class)->issue('openai', 'sk-live-value');

    $this->actingAsUser();

    // Nothing to say, so it says nothing and takes one line.
    expect(isOpen(Livewire::test(ProvidersIndex::class)->html(), 'openai'))->toBeFalse();
});

it('opens a provider that needs attention, and says which thing is wrong', function (): void {
    // A closed row is a claim that nothing here needs you. The claim has to be
    // true, so a provider with no credential does not get to hide.
    config()->set('pandora.providers.connections', [
        'openai' => ['adapter' => 'openai-compatible', 'base_url' => 'https://api.openai.test/v1'],
    ]);
    app()->forgetInstance(ProviderManager::class);

    $this->actingAsUser();

    $html = Livewire::test(ProvidersIndex::class)->html();

    expect(isOpen($html, 'openai'))->toBeTrue()
        ->and($html)->toContain('no credential');
});

it('opens a provider that is not answering', function (): void {
    config()->set('pandora.providers.health.failure_threshold', 1);
    app(CredentialManager::class)->issue('openai', 'sk-live-value');
    app(ProviderHealthMonitor::class)->recordFailure('openai', 'Connection refused by the gateway');

    $this->actingAsUser();

    $html = Livewire::test(ProvidersIndex::class)->html();

    expect(isOpen($html, 'openai'))->toBeTrue()
        ->and($html)->toContain('not answering');
});

it('opens a provider charging against prices nobody has confirmed', function (): void {
    config()->set('pandora.models.pricing_stale_after_days', 30);
    app(CredentialManager::class)->issue('openai', 'sk-live-value');

    app(ModelCatalog::class)->seedFromConfig([[
        'provider' => 'openai',
        'key' => 'gpt-4o-mini',
        'context_limit' => 128_000,
        'capabilities' => ['tools'],
        'input_price' => 0.15,
        'output_price' => 0.60,
        'pricing_source' => 'https://example.test/pricing',
        'pricing_date' => now()->subDays(200)->toDateString(),
    ]]);

    $this->actingAsUser();

    $html = Livewire::test(ProvidersIndex::class)->html();

    expect(isOpen($html, 'openai'))->toBeTrue()
        ->and($html)->toContain('stale pricing');
});

it('does not treat a provider nobody has probed as one that is broken', function (): void {
    // `unknown` is the normal state of a fresh installation. Opening every row
    // on day one would undo the whole point of collapsing them.
    config()->set('pandora.providers.connections', [
        'openai' => ['adapter' => 'openai-compatible', 'base_url' => 'https://api.openai.test/v1'],
    ]);
    app()->forgetInstance(ProviderManager::class);

    app(CredentialManager::class)->issue('openai', 'sk-live-value');

    $this->actingAsUser();

    $html = Livewire::test(ProvidersIndex::class)->html();

    expect(isOpen($html, 'openai'))->toBeFalse()
        // Still said, just not shouted.
        ->and($html)->toContain('Unknown')
        ->and($html)->not->toContain('not answering');
});
