<?php

declare(strict_types=1);

use Pandora\Core\Tenancy\TenantContext;
use Pandora\Core\Tenancy\TenantManager;
use Pandora\Providers\Credentials\CredentialManager;

/**
 * Phase 3 acceptance criterion 34 -- one tenant's key is not another's.
 *
 * `ProviderCredential` deliberately does NOT use the BelongsToTenant global
 * scope, because a deployment-wide credential has a null tenant_id and the
 * scope would hide it exactly when it is needed. Isolation is therefore a
 * WHERE clause in the resolver, which makes this file the thing that proves
 * it rather than a convention that implies it.
 */
function asTenant(string $id, Closure $callback): mixed
{
    return app(TenantManager::class)->with(new TenantContext($id), $callback);
}

function manager(): CredentialManager
{
    return app(CredentialManager::class);
}

beforeEach(function (): void {
    config()->set('pandora.providers.connections.openai.api_key', null);
});

it('does not resolve another tenant\'s credential', function (): void {
    asTenant('acme', fn () => manager()->issue('openai', 'sk-acme-only'));

    $resolved = asTenant('globex', fn () => manager()->resolve('openai'));

    expect($resolved)->toBeNull();
});

it('does not fall through to another tenant\'s credential when its own is revoked', function (): void {
    asTenant('acme', fn () => manager()->issue('openai', 'sk-acme-only'));

    asTenant('globex', function (): void {
        $own = manager()->issue('openai', 'sk-globex');
        manager()->revoke($own);

        expect(manager()->resolve('openai'))->toBeNull();
    });
});

it('lets both tenants fall back to the deployment credential', function (): void {
    manager()->issue('openai', 'sk-shared-deployment');

    foreach (['acme', 'globex'] as $tenant) {
        expect(asTenant($tenant, fn () => manager()->resolve('openai'))?->secret())
            ->toBe('sk-shared-deployment');
    }
});

it('does not list another tenant\'s credentials', function (): void {
    asTenant('acme', fn () => manager()->issue('openai', 'sk-acme-only'));
    manager()->issue('openai', 'sk-deployment');

    $visible = asTenant('globex', fn () => manager()->stored('openai'));

    expect($visible)->toHaveCount(1)
        ->and($visible->first()?->tenant_id)->toBeNull();
});

it('refuses to serialise a resolved credential onto a queue payload', function (): void {
    manager()->issue('openai', 'sk-never-on-a-payload');

    $credential = manager()->resolve('openai');

    expect(fn (): string => serialize($credential))
        ->toThrow(LogicException::class, 'may not be serialised');
});

it('masks the secret in every debugging and encoding path', function (): void {
    manager()->issue('openai', 'sk-masked-everywhere');

    $credential = manager()->resolve('openai');

    expect((string) json_encode($credential))->not->toContain('sk-masked-everywhere')
        ->and((string) json_encode($credential))->toContain('[redacted]')
        ->and(print_r($credential, true))->not->toContain('sk-masked-everywhere')
        ->and(var_export(($credential)->__debugInfo(), true))->not->toContain('sk-masked-everywhere');
});
