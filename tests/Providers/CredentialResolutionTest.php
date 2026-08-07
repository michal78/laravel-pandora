<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Pandora\Core\Tenancy\TenantContext;
use Pandora\Core\Tenancy\TenantManager;
use Pandora\Providers\Credentials\CredentialManager;
use Pandora\Providers\Credentials\CredentialSource;
use Pandora\Providers\Credentials\ProviderCredential;

/**
 * Phase 3 acceptance criteria 31 and 32 -- resolution order, and encryption
 * at rest.
 */
function credentials(): CredentialManager
{
    return app(CredentialManager::class);
}

function withTenant(string $id, Closure $callback): mixed
{
    return app(TenantManager::class)->with(new TenantContext($id), $callback);
}

it('falls back to the configured value when nothing is stored', function (): void {
    config()->set('pandora.providers.connections.openai.api_key', 'sk-from-config');

    $credential = credentials()->resolve('openai');

    expect($credential)->not->toBeNull()
        ->and($credential->secret())->toBe('sk-from-config')
        ->and($credential->source)->toBe(CredentialSource::Config);
});

it('returns null when the deployment has no credential for the provider', function (): void {
    config()->set('pandora.providers.connections.openai.api_key', null);

    expect(credentials()->resolve('openai'))->toBeNull();
});

it('prefers a deployment credential over the configured one', function (): void {
    config()->set('pandora.providers.connections.openai.api_key', 'sk-from-config');

    credentials()->issue('openai', 'sk-from-database');

    $credential = credentials()->resolve('openai');

    expect($credential?->secret())->toBe('sk-from-database')
        ->and($credential?->source)->toBe(CredentialSource::Deployment);
});

it('prefers a tenant credential over the deployment one', function (): void {
    credentials()->issue('openai', 'sk-deployment');

    withTenant('acme', function (): void {
        credentials()->issue('openai', 'sk-acme');

        expect(credentials()->resolve('openai')?->secret())->toBe('sk-acme');
    });

    // And outside that tenant, the deployment credential is still the answer.
    expect(credentials()->resolve('openai')?->secret())->toBe('sk-deployment');
});

it('prefers an agent credential over every broader scope', function (): void {
    credentials()->issue('openai', 'sk-deployment');

    withTenant('acme', function (): void {
        credentials()->issue('openai', 'sk-acme');
        credentials()->issue('openai', 'sk-support-agent', agentId: '01JQ00000000000000000AGENT');

        $resolved = credentials()->forAgent(
            '01JQ00000000000000000AGENT',
            fn () => credentials()->resolve('openai'),
        );

        expect($resolved?->secret())->toBe('sk-support-agent')
            ->and($resolved?->source)->toBe(CredentialSource::Agent);
    });
});

it('does not let one agent resolve another agent\'s credential', function (): void {
    credentials()->issue('openai', 'sk-deployment');
    credentials()->issue('openai', 'sk-billing-agent', agentId: '01JQ000000000000000BILLING');

    $resolved = credentials()->forAgent(
        '01JQ000000000000000SUPPORT',
        fn () => credentials()->resolve('openai'),
    );

    expect($resolved?->secret())->toBe('sk-deployment');
});

it('leaves the agent scope behind when the callback returns', function (): void {
    credentials()->issue('openai', 'sk-deployment');
    credentials()->issue('openai', 'sk-agent', agentId: '01JQ00000000000000000AGENT');

    credentials()->forAgent('01JQ00000000000000000AGENT', fn () => credentials()->resolve('openai'));

    expect(credentials()->resolve('openai')?->secret())->toBe('sk-deployment');
});

it('encrypts the secret at rest', function (): void {
    credentials()->issue('openai', 'sk-plaintext-would-be-a-bug');

    $raw = (string) DB::table('pandora_provider_credentials')->value('secret');

    expect($raw)->not->toContain('sk-plaintext-would-be-a-bug')
        ->and($raw)->not->toBe('')
        // And it decrypts back through the model, so the encryption is real
        // rather than the column simply being empty.
        ->and(ProviderCredential::query()->first()?->secret)->toBe('sk-plaintext-would-be-a-bug');
});

it('records a fingerprint that identifies the key without revealing it', function (): void {
    $credential = credentials()->issue('openai', 'sk-abcdefghijklmnop');

    expect($credential->fingerprint)->toHaveLength(12)
        ->and('sk-abcdefghijklmnop')->not->toContain($credential->fingerprint)
        ->and($credential->toCredential()->hint())->toBe('****mnop');
});

it('never exposes the secret through the model\'s array or JSON form', function (): void {
    $credential = credentials()->issue('openai', 'sk-secret-value');

    expect($credential->toArray())->not->toHaveKey('secret')
        ->and((string) json_encode($credential))->not->toContain('sk-secret-value');
});
