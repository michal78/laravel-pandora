<?php

declare(strict_types=1);

use Pandora\Contracts\ModelRouter;
use Pandora\Exceptions\NoModelAvailable;
use Pandora\Providers\Catalog\ModelCatalog;
use Pandora\Providers\Data\ProviderCapabilities;
use Pandora\Providers\Health\ProviderHealthMonitor;
use Pandora\Providers\ProviderManager;
use Pandora\Providers\Routing\RoutingRequest;
use Pandora\Providers\Routing\RoutingSource;
use Pandora\Tests\Support\MakesRuns;

uses(MakesRuns::class);

/**
 * Phase 3 acceptance criteria 15, 16 and 22 -- precedence, capability
 * filtering, and a decision you can read afterwards.
 */
function router(): ModelRouter
{
    return app(ModelRouter::class);
}

beforeEach(function (): void {
    config()->set('pandora.providers.connections', [
        'fake' => ['adapter' => 'fake'],
        'backup' => ['adapter' => 'fake'],
    ]);
    config()->set('pandora.providers.default', 'fake');
    config()->set('pandora.models.default', 'config-model');

    // ProviderManager is a singleton built from config at resolution time, so
    // a connection list edited afterwards has to invalidate it -- otherwise
    // the router judges availability against the config it booted with.
    app()->forgetInstance(ProviderManager::class);
    app()->forgetInstance(ModelRouter::class);
});

it('follows the documented precedence order', function (): void {
    $agent = $this->makeAgent(['default_provider' => 'fake', 'default_model' => 'agent-model']);

    $full = new RoutingRequest(
        agent: $agent,
        explicitProvider: 'fake', explicitModel: 'explicit-model',
        runProvider: 'fake', runModel: 'run-model',
        conversationProvider: 'fake', conversationModel: 'conversation-model',
    );

    expect(router()->resolve($full)->modelKey)->toBe('explicit-model')
        ->and(router()->resolve($full)->source)->toBe(RoutingSource::Explicit);

    $withoutExplicit = new RoutingRequest(
        agent: $agent,
        runProvider: 'fake', runModel: 'run-model',
        conversationProvider: 'fake', conversationModel: 'conversation-model',
    );

    expect(router()->resolve($withoutExplicit)->modelKey)->toBe('run-model')
        ->and(router()->resolve($withoutExplicit)->source)->toBe(RoutingSource::Run);

    $conversationOnly = new RoutingRequest(
        agent: $agent,
        conversationProvider: 'fake', conversationModel: 'conversation-model',
    );

    expect(router()->resolve($conversationOnly)->modelKey)->toBe('conversation-model')
        ->and(router()->resolve($conversationOnly)->source)->toBe(RoutingSource::Conversation);

    expect(router()->resolve(new RoutingRequest($agent))->modelKey)->toBe('agent-model')
        ->and(router()->resolve(new RoutingRequest($agent))->source)->toBe(RoutingSource::Agent);
});

it('falls back to the configured default when the agent states none', function (): void {
    $agent = $this->makeAgent(['default_provider' => null, 'default_model' => null]);

    $decision = router()->resolve(new RoutingRequest($agent));

    expect($decision->providerKey)->toBe('fake')
        ->and($decision->modelKey)->toBe('config-model')
        ->and($decision->source)->toBe(RoutingSource::Config);
});

it('walks the fallback chain in the order it is written', function (): void {
    $agent = $this->makeAgent([
        'default_provider' => 'fake',
        'default_model' => 'primary',
        'fallback_models' => ['second', 'backup/third'],
    ]);

    $request = new RoutingRequest($agent);

    expect(router()->resolve($request)->reference())->toBe('fake/primary')
        ->and(router()->resolve($request->excluding(['fake/primary']))->reference())->toBe('fake/second')
        ->and(router()->resolve($request->excluding(['fake/primary', 'fake/second']))->reference())
        ->toBe('backup/third');
});

it('keeps a bare fallback name on the agent\'s own provider', function (): void {
    $agent = $this->makeAgent([
        'default_provider' => 'backup',
        'default_model' => 'primary',
        'fallback_models' => ['second'],
    ]);

    expect(router()->resolve((new RoutingRequest($agent))->excluding(['backup/primary']))->providerKey)
        ->toBe('backup');
});

it('marks anything reached after a failure as a fallback, whatever level wrote it', function (): void {
    $agent = $this->makeAgent([
        'default_provider' => 'fake',
        'default_model' => 'primary',
        'fallback_models' => ['second'],
    ]);

    $decision = router()->resolve((new RoutingRequest($agent))->excluding(['fake/primary']));

    expect($decision->source)->toBe(RoutingSource::Fallback)
        ->and($decision->attempt)->toBe(2);
});

it('treats a model named at two levels as one candidate', function (): void {
    // Left in, the "fallback" would retry the model that just failed.
    $agent = $this->makeAgent([
        'default_provider' => 'fake',
        'default_model' => 'primary',
        'fallback_models' => ['primary', 'second'],
    ]);

    expect(router()->resolve((new RoutingRequest($agent))->excluding(['fake/primary']))->modelKey)
        ->toBe('second');
});

it('skips a model that lacks a capability the request requires', function (): void {
    app(ModelCatalog::class)->seedFromConfig([
        ['provider' => 'fake', 'key' => 'text-only', 'capabilities' => ['streaming', 'tools']],
        ['provider' => 'fake', 'key' => 'multimodal', 'capabilities' => ['streaming', 'tools', 'vision']],
    ]);

    $agent = $this->makeAgent([
        'default_provider' => 'fake',
        'default_model' => 'text-only',
        'fallback_models' => ['multimodal'],
    ]);

    $decision = router()->resolve(new RoutingRequest(
        agent: $agent,
        required: new ProviderCapabilities(vision: true),
    ));

    expect($decision->modelKey)->toBe('multimodal')
        ->and($decision->skipped)->toBe(['fake/text-only: model lacks a required capability']);
});

it('uses a model the catalog has never heard of', function (): void {
    // The catalog is an enrichment. Treating "we know nothing about it" as
    // "it cannot do that" would break every deployment that has not synced.
    $agent = $this->makeAgent(['default_provider' => 'fake', 'default_model' => 'never-synced']);

    expect(router()->resolve(new RoutingRequest(
        agent: $agent,
        required: new ProviderCapabilities(vision: true),
    ))->modelKey)->toBe('never-synced');
});

it('skips a degraded provider', function (): void {
    config()->set('pandora.providers.health.failure_threshold', 1);
    app(ProviderHealthMonitor::class)->recordFailure('fake', 'Down');

    $agent = $this->makeAgent([
        'default_provider' => 'fake',
        'default_model' => 'primary',
        'fallback_models' => ['backup/second'],
    ]);

    $decision = router()->resolve(new RoutingRequest($agent));

    expect($decision->reference())->toBe('backup/second')
        ->and($decision->skipped)->toBe(['fake/primary: provider is degraded']);
});

it('routes to a recovered provider again', function (): void {
    config()->set('pandora.providers.health.failure_threshold', 1);
    app(ProviderHealthMonitor::class)->recordFailure('fake', 'Down');
    app(ProviderHealthMonitor::class)->recordSuccess('fake');

    $agent = $this->makeAgent(['default_provider' => 'fake', 'default_model' => 'primary']);

    expect(router()->resolve(new RoutingRequest($agent))->reference())->toBe('fake/primary');
});

it('skips a provider that is not configured', function (): void {
    $agent = $this->makeAgent([
        'default_provider' => 'never-set-up',
        'default_model' => 'primary',
        'fallback_models' => ['backup/second'],
    ]);

    expect(router()->resolve(new RoutingRequest($agent))->reference())->toBe('backup/second');
});

it('skips a disabled or deprecated model', function (): void {
    app(ModelCatalog::class)->seedFromConfig([
        ['provider' => 'fake', 'key' => 'retired', 'deprecated_at' => now()->subDay()->toDateTimeString()],
        ['provider' => 'fake', 'key' => 'switched-off', 'enabled' => false],
        ['provider' => 'fake', 'key' => 'current'],
    ]);

    $agent = $this->makeAgent([
        'default_provider' => 'fake',
        'default_model' => 'retired',
        'fallback_models' => ['switched-off', 'current'],
    ]);

    $decision = router()->resolve(new RoutingRequest($agent));

    expect($decision->modelKey)->toBe('current')
        ->and($decision->skipped)->toBe([
            'fake/retired: model is deprecated',
            'fake/switched-off: model is disabled',
        ]);
});

it('requires a strictly larger context window after an overflow', function (): void {
    app(ModelCatalog::class)->seedFromConfig([
        ['provider' => 'fake', 'key' => 'small', 'context_limit' => 8_000],
        ['provider' => 'fake', 'key' => 'same-size', 'context_limit' => 8_000],
        ['provider' => 'fake', 'key' => 'large', 'context_limit' => 200_000],
    ]);

    $agent = $this->makeAgent([
        'default_provider' => 'fake',
        'default_model' => 'small',
        'fallback_models' => ['same-size', 'large'],
    ]);

    $decision = router()->resolve(
        (new RoutingRequest($agent))->excluding(['fake/small'])->needingContext(8_000),
    );

    // A different model of the same size just overflows again.
    expect($decision->modelKey)->toBe('large');
});

it('explains itself when nothing is available', function (): void {
    config()->set('pandora.providers.health.failure_threshold', 1);
    app(ProviderHealthMonitor::class)->recordFailure('fake', 'Down');
    app(ProviderHealthMonitor::class)->recordFailure('backup', 'Down');

    $agent = $this->makeAgent([
        'slug' => 'support',
        'default_provider' => 'fake',
        'default_model' => 'primary',
        'fallback_models' => ['backup/second'],
    ]);

    // "No model available" on its own sends an operator hunting through four
    // config files.
    expect(fn () => router()->resolve(new RoutingRequest($agent)))
        ->toThrow(NoModelAvailable::class, 'fake/primary: provider is degraded');
});
