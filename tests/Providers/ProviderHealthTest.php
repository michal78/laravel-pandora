<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Pandora\Pandora\Audit\AuditLog;
use Pandora\Pandora\Jobs\ProbeProviderHealth;
use Pandora\Pandora\Providers\Data\ProviderHealth;
use Pandora\Pandora\Providers\Health\ProviderHealthMonitor;
use Pandora\Pandora\Providers\ProviderManager;

/**
 * Phase 3 acceptance criteria 24 and 25 -- degradation, recovery, and a probe
 * that cannot hurt anybody.
 */
function monitor(): ProviderHealthMonitor
{
    return app(ProviderHealthMonitor::class);
}

it('treats an unprobed provider as usable', function (): void {
    // Refusing to use a provider nobody has checked would make health tracking
    // an outage of its own on a fresh installation.
    expect(monitor()->isUsable('openai'))->toBeTrue()
        ->and(monitor()->status('openai')->status)->toBe('unknown');
});

it('does not degrade a provider on a single failure', function (): void {
    config()->set('pandora.providers.health.failure_threshold', 3);

    monitor()->recordFailure('openai', 'Connection timed out');

    expect(monitor()->isUsable('openai'))->toBeTrue();
});

it('degrades a provider after a run of failures', function (): void {
    config()->set('pandora.providers.health.failure_threshold', 3);

    foreach (range(1, 3) as $attempt) {
        monitor()->recordFailure('openai', "Connection timed out (attempt {$attempt})");
    }

    expect(monitor()->isUsable('openai'))->toBeFalse()
        ->and(monitor()->status('openai')->status)->toBe('degraded');
});

it('recovers on the first success', function (): void {
    config()->set('pandora.providers.health.failure_threshold', 2);

    monitor()->recordFailure('openai', 'Down');
    monitor()->recordFailure('openai', 'Down');
    monitor()->recordSuccess('openai', latencyMs: 120);

    expect(monitor()->isUsable('openai'))->toBeTrue()
        ->and(monitor()->status('openai')->latencyMs)->toBe(120)
        ->and(monitor()->status('openai')->message)->toBeNull();
});

it('audits the transition into and out of degradation, once each', function (): void {
    config()->set('pandora.providers.health.failure_threshold', 2);

    monitor()->recordFailure('openai', 'Down');
    monitor()->recordFailure('openai', 'Down');
    monitor()->recordFailure('openai', 'Still down');
    monitor()->recordSuccess('openai');
    monitor()->recordSuccess('openai');

    // Once each: an alert on every failed probe of a provider that has been
    // down all morning is noise, and noise gets muted.
    expect(AuditLog::query()->where('action', 'provider.degraded')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'provider.recovered')->count())->toBe(1);
});

it('reports every provider as usable when health tracking is switched off', function (): void {
    config()->set('pandora.providers.health.failure_threshold', 1);
    monitor()->recordFailure('openai', 'Down');

    expect(monitor()->isUsable('openai'))->toBeFalse();

    config()->set('pandora.providers.health.enabled', false);

    expect(monitor()->isUsable('openai'))->toBeTrue();
});

it('records what a probe found', function (): void {
    config()->set('pandora.providers.connections', [
        'fake' => ['adapter' => 'fake'],
    ]);

    app(ProbeProviderHealth::class, ['providerKey' => 'fake'])->handle(
        app(ProviderManager::class),
        monitor(),
    );

    expect(monitor()->status('fake')->status)->toBe('healthy');
});

it('records an unreachable provider rather than throwing', function (): void {
    Http::fake(['api.openai.test/*' => Http::response(['error' => 'nope'], 503)]);

    config()->set('pandora.providers.connections', [
        'openai' => [
            'adapter' => 'openai-compatible',
            'base_url' => 'https://api.openai.test/v1',
            'api_key' => 'sk-test',
        ],
    ]);

    // A health probe exists to make failures visible. One that threw would be
    // a new source of exactly the noise it was built to explain.
    (new ProbeProviderHealth)->handle(
        app(ProviderManager::class),
        monitor(),
    );

    expect(monitor()->status('openai')->status)->toBe('unknown')
        ->and(monitor()->status('openai')->message)->toContain('503');
});

it('records a misconfigured provider as a failure instead of raising', function (): void {
    config()->set('pandora.providers.connections', [
        // No base_url: resolving the client throws InvalidConfiguration.
        'broken' => ['adapter' => 'openai-compatible'],
    ]);

    (new ProbeProviderHealth)->handle(
        app(ProviderManager::class),
        monitor(),
    );

    expect(monitor()->status('broken')->status)->toBe('unknown')
        ->and(monitor()->status('broken')->message)->not->toBeNull();
});

it('does nothing at all when health tracking is disabled', function (): void {
    config()->set('pandora.providers.health.enabled', false);
    config()->set('pandora.providers.connections', ['fake' => ['adapter' => 'fake']]);

    (new ProbeProviderHealth)->handle(
        app(ProviderManager::class),
        monitor(),
    );

    expect(monitor()->status('fake')->status)->toBe('unknown');
});

it('records the health a provider itself reports', function (): void {
    monitor()->record('openai', ProviderHealth::degraded('HTTP 500'));

    expect(monitor()->status('openai')->message)->toBe('HTTP 500');
});
