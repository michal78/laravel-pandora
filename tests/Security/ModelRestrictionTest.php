<?php

declare(strict_types=1);

use Pandora\Contracts\ModelRouter;
use Pandora\Exceptions\NoModelAvailable;
use Pandora\Providers\ProviderManager;
use Pandora\Providers\Routing\RoutingRequest;
use Pandora\Tests\Support\MakesRuns;

uses(MakesRuns::class);

/**
 * Phase 3 acceptance criterion 17 -- a tenant's model restrictions bind the
 * whole route, not just its first hop.
 *
 * The restriction is applied to the CANDIDATE SET, before any other filter.
 * Applied afterwards it would be an ordering coincidence: a fallback chain
 * would walk straight out of it the first time the permitted model failed.
 */
beforeEach(function (): void {
    config()->set('pandora.providers.connections', [
        'fake' => ['adapter' => 'fake'],
        'backup' => ['adapter' => 'fake'],
    ]);

    app()->forgetInstance(ProviderManager::class);
    app()->forgetInstance(ModelRouter::class);
});

function restrictedRouter(): ModelRouter
{
    return app(ModelRouter::class);
}

it('does not route a tenant to a model it is not permitted', function (): void {
    config()->set('pandora.models.tenant_restrictions', [
        'acme' => ['fake/permitted'],
    ]);

    $agent = $this->makeAgent([
        'default_provider' => 'fake',
        'default_model' => 'forbidden',
        'fallback_models' => ['permitted'],
    ]);

    $decision = restrictedRouter()->resolve(new RoutingRequest($agent, tenantId: 'acme'));

    expect($decision->modelKey)->toBe('permitted')
        ->and($decision->skipped)->toBe(['fake/forbidden: not permitted for this tenant']);
});

it('does not let a fallback chain escape the restriction', function (): void {
    config()->set('pandora.models.tenant_restrictions', [
        'acme' => ['fake/permitted'],
    ]);

    $agent = $this->makeAgent([
        'default_provider' => 'fake',
        'default_model' => 'permitted',
        'fallback_models' => ['forbidden', 'backup/also-forbidden'],
    ]);

    // The permitted model has failed. There is nowhere legitimate left to go,
    // and the correct outcome is a failed run rather than a quiet hop onto a
    // model this tenant may not use.
    expect(fn () => restrictedRouter()->resolve(
        (new RoutingRequest($agent, tenantId: 'acme'))->excluding(['fake/permitted']),
    ))->toThrow(NoModelAvailable::class, 'not permitted for this tenant');
});

it('permits a whole provider with a wildcard', function (): void {
    config()->set('pandora.models.tenant_restrictions', [
        'acme' => ['backup/*'],
    ]);

    $agent = $this->makeAgent([
        'default_provider' => 'fake',
        'default_model' => 'anything',
        'fallback_models' => ['backup/whatever'],
    ]);

    expect(restrictedRouter()->resolve(new RoutingRequest($agent, tenantId: 'acme'))->reference())
        ->toBe('backup/whatever');
});

it('leaves a tenant with no entry unrestricted', function (): void {
    config()->set('pandora.models.tenant_restrictions', [
        'acme' => ['fake/permitted'],
    ]);

    $agent = $this->makeAgent(['default_provider' => 'fake', 'default_model' => 'anything']);

    expect(restrictedRouter()->resolve(new RoutingRequest($agent, tenantId: 'globex'))->modelKey)
        ->toBe('anything');
});

it('applies one tenant\'s restrictions to that tenant only', function (): void {
    config()->set('pandora.models.tenant_restrictions', [
        'acme' => ['fake/small-model'],
        'globex' => ['fake/big-model'],
    ]);

    $agent = $this->makeAgent([
        'default_provider' => 'fake',
        'default_model' => 'small-model',
        'fallback_models' => ['big-model'],
    ]);

    expect(restrictedRouter()->resolve(new RoutingRequest($agent, tenantId: 'acme'))->modelKey)
        ->toBe('small-model')
        ->and(restrictedRouter()->resolve(new RoutingRequest($agent, tenantId: 'globex'))->modelKey)
        ->toBe('big-model');
});

it('ignores restrictions entirely when there is no tenant', function (): void {
    config()->set('pandora.models.tenant_restrictions', ['acme' => ['fake/permitted']]);

    $agent = $this->makeAgent(['default_provider' => 'fake', 'default_model' => 'anything']);

    expect(restrictedRouter()->resolve(new RoutingRequest($agent))->modelKey)->toBe('anything');
});
