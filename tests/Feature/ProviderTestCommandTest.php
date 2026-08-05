<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Pandora\Pandora\Exceptions\Provider\ProviderQuotaExhausted;
use Pandora\Pandora\Providers\Adapters\FakeProvider;
use Pandora\Pandora\Providers\Credentials\Credential;
use Pandora\Pandora\Providers\Health\ProviderHealthMonitor;
use Pandora\Pandora\Providers\ProviderManager;

/**
 * Phase 3 acceptance criterion 38 -- the one command that makes a real call,
 * and what it is allowed to print about it.
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
});

it('reports a successful round trip', function (): void {
    /** @var FakeProvider $provider */
    $provider = app(ProviderManager::class)->provider('fake');
    $provider->willRespondWith('ready');

    $this->artisan('pandora:provider:test', ['connection' => 'fake'])
        ->expectsOutputToContain('ready')
        ->expectsOutputToContain('Provider [fake] answered.')
        ->assertSuccessful();
});

it('names a connection that does not exist rather than guessing', function (): void {
    $this->artisan('pandora:provider:test', ['connection' => 'nonexistent'])
        ->expectsOutputToContain('No provider connection named [nonexistent]')
        ->assertFailed();
});

it('reports a classified failure without printing the key', function (): void {
    Http::fake(['api.openai.test/*' => Http::response([
        'error' => [
            'message' => 'You exceeded your current quota. Key sk-a-very-secret-value has no credit.',
            'code' => 'insufficient_quota',
        ],
    ], 429)]);

    // The classification is the useful part: "rate limited" and "no credit"
    // look identical from the outside and need different responses.
    $this->artisan('pandora:provider:test', ['connection' => 'openai'])
        ->expectsOutputToContain((new ProviderQuotaExhausted('x'))->errorCode())
        ->assertFailed();
});

it('never prints the credential, not even a prefix', function (): void {
    // The provider echoes the key back in its own error text, which OpenAI
    // genuinely does on a 401. The command must not pass that through.
    Http::fake(['api.openai.test/*' => Http::response([
        'error' => ['message' => 'Incorrect API key provided: sk-a-very-secret-value.'],
    ], 401)]);

    Artisan::call('pandora:provider:test', ['connection' => 'openai']);

    $output = Artisan::output();

    expect($output)->not->toContain('sk-a-very-secret-value')
        ->and($output)->not->toContain('sk-a-very')
        // What it prints instead identifies the key without revealing it, and
        // shares no prefix with it.
        ->and($output)->toContain(Credential::fingerprintOf('sk-a-very-secret-value'));
});

it('probes health without sending a prompt', function (): void {
    /** @var FakeProvider $provider */
    $provider = app(ProviderManager::class)->provider('fake');
    $provider->willRespondWith('should never be used');

    $this->artisan('pandora:provider:test', ['connection' => 'fake', '--health' => true])
        ->expectsOutputToContain('healthy')
        ->assertSuccessful();

    expect($provider->receivedRequests())->toBe([]);
});

it('records what it learned about the provider\'s health', function (): void {
    $this->artisan('pandora:provider:test', ['connection' => 'fake', '--health' => true])
        ->assertSuccessful();

    expect(app(ProviderHealthMonitor::class)->status('fake')->status)->toBe('healthy');
});

it('falls back to the default connection when none is named', function (): void {
    /** @var FakeProvider $provider */
    $provider = app(ProviderManager::class)->provider('fake');
    $provider->willRespondWith('ready');

    $this->artisan('pandora:provider:test')
        ->expectsOutputToContain('Provider [fake] answered.')
        ->assertSuccessful();
});
