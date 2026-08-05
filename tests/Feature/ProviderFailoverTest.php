<?php

declare(strict_types=1);

use Pandora\Pandora\Agents\Agent;
use Pandora\Pandora\Agents\AgentRunner;
use Pandora\Pandora\Audit\AuditLog;
use Pandora\Pandora\Contracts\ModelRouter;
use Pandora\Pandora\Exceptions\Provider\ContextOverflow;
use Pandora\Pandora\Exceptions\Provider\ProviderRateLimited;
use Pandora\Pandora\Exceptions\Provider\ProviderRejectedRequest;
use Pandora\Pandora\Exceptions\Provider\ProviderUnavailable;
use Pandora\Pandora\Providers\Adapters\FakeProvider;
use Pandora\Pandora\Providers\Catalog\ModelCatalog;
use Pandora\Pandora\Providers\Health\ProviderHealthMonitor;
use Pandora\Pandora\Providers\ProviderManager;
use Pandora\Pandora\Runs\Enums\RunState;
use Pandora\Pandora\Runs\Enums\RunStepType;
use Pandora\Pandora\Tests\Support\MakesRuns;
use Pandora\Pandora\Tests\TestCase;

uses(MakesRuns::class);

/**
 * Phase 3 acceptance criteria 18 to 23 -- failover as behaviour, not as a
 * configuration option nobody has watched work.
 */
beforeEach(function (): void {
    config()->set('pandora.providers.connections', [
        'fake' => ['adapter' => 'fake'],
        'backup' => ['adapter' => 'fake'],
    ]);

    // No sleeping in tests; the delay is proven separately by reading config.
    config()->set('pandora.providers.retry.delay_ms', 0);

    app()->forgetInstance(ProviderManager::class);
    app()->forgetInstance(ModelRouter::class);
});

function primary(): FakeProvider
{
    /** @var FakeProvider $provider */
    $provider = app(ProviderManager::class)->provider('fake');

    return $provider;
}

function backup(): FakeProvider
{
    /** @var FakeProvider $provider */
    $provider = app(ProviderManager::class)->provider('backup');

    return $provider;
}

function agentWithFallback(array $overrides = []): Agent
{
    /** @var TestCase $test */
    $test = test();

    return $test->makeAgent(array_merge([
        'default_provider' => 'fake',
        'default_model' => 'primary-model',
        'fallback_models' => ['backup/backup-model'],
    ], $overrides));
}

it('fails over to the next model when a provider is unavailable', function (): void {
    primary()->willThrow(new ProviderUnavailable('Bad gateway', 'fake', 'primary-model'));
    backup()->willRespondWith('Answered by the backup.');

    $run = app(AgentRunner::class)->agent(agentWithFallback())->run('Hello');

    expect($run->state)->toBe(RunState::Completed)
        ->and($run->output)->toBe('Answered by the backup.')
        ->and($run->provider_key)->toBe('backup')
        ->and($run->model_key)->toBe('backup-model');
});

it('records both the failed attempt and the hop that replaced it', function (): void {
    primary()->willThrow(new ProviderUnavailable('Bad gateway', 'fake', 'primary-model'));
    backup()->willRespondWith('Answered by the backup.');

    $run = app(AgentRunner::class)->agent(agentWithFallback())->run('Hello');

    $routing = $run->steps()->where('type', RunStepType::ModelRouting->value)->get();

    // Two routing steps: a run that quietly finished on the second model has
    // to be explainable months later without guessing.
    expect($routing)->toHaveCount(2)
        ->and($routing[0]->payload['model'])->toBe('primary-model')
        ->and($routing[1]->payload['model'])->toBe('backup-model')
        ->and($routing[1]->payload['source'])->toBe('fallback');

    $failed = $run->steps()
        ->where('type', RunStepType::ModelRequest->value)
        ->where('status', 'failed')
        ->first();

    expect($failed?->error_class)->toBe(ProviderUnavailable::class);
});

it('audits the failover', function (): void {
    primary()->willThrow(new ProviderUnavailable('Bad gateway', 'fake', 'primary-model'));
    backup()->willRespondWith('Fine.');

    $run = app(AgentRunner::class)->agent(agentWithFallback())->run('Hello');

    $audit = AuditLog::query()->where('action', 'provider.failover')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->run_id)->toBe($run->getKey())
        ->and($audit->metadata['from'])->toBe('fake/primary-model')
        ->and($audit->severity)->toBe('warning');
});

it('retries a rate limit against the same model before giving up on it', function (): void {
    config()->set('pandora.providers.retry.rate_limit_attempts', 2);

    // Two 429s, then success -- on the SAME provider. A chain that fired on
    // every 429 would spend the day answering from the wrong model.
    primary()
        ->willThrow(new ProviderRateLimited('Slow down', 'fake', 'primary-model'))
        ->willThrow(new ProviderRateLimited('Slow down', 'fake', 'primary-model'))
        ->willRespondWith('Answered after waiting.');

    $run = app(AgentRunner::class)->agent(agentWithFallback())->run('Hello');

    expect($run->state)->toBe(RunState::Completed)
        ->and($run->provider_key)->toBe('fake')
        ->and($run->output)->toBe('Answered after waiting.')
        ->and(AuditLog::query()->where('action', 'provider.failover')->count())->toBe(0);
});

it('fails over once the rate-limit attempts are exhausted', function (): void {
    config()->set('pandora.providers.retry.rate_limit_attempts', 1);

    primary()
        ->willThrow(new ProviderRateLimited('Slow down', 'fake', 'primary-model'))
        ->willThrow(new ProviderRateLimited('Slow down', 'fake', 'primary-model'));

    backup()->willRespondWith('Answered by the backup.');

    $run = app(AgentRunner::class)->agent(agentWithFallback())->run('Hello');

    expect($run->state)->toBe(RunState::Completed)
        ->and($run->provider_key)->toBe('backup');
});

it('chooses a larger context window after an overflow', function (): void {
    app(ModelCatalog::class)->seedFromConfig([
        ['provider' => 'fake', 'key' => 'primary-model', 'context_limit' => 8_000],
        ['provider' => 'fake', 'key' => 'same-size', 'context_limit' => 8_000],
        ['provider' => 'backup', 'key' => 'backup-model', 'context_limit' => 200_000],
    ]);

    primary()->willThrow(new ContextOverflow('Prompt is too long', 'fake', 'primary-model'));
    backup()->willRespondWith('Answered with room to spare.');

    $run = app(AgentRunner::class)->agent(agentWithFallback([
        'fallback_models' => ['same-size', 'backup/backup-model'],
    ]))->run('Hello');

    // `same-size` is skipped: a different model of the same size would just
    // overflow again.
    expect($run->state)->toBe(RunState::Completed)
        ->and($run->model_key)->toBe('backup-model');
});

it('fails clearly when no larger context window exists', function (): void {
    app(ModelCatalog::class)->seedFromConfig([
        ['provider' => 'fake', 'key' => 'primary-model', 'context_limit' => 8_000],
        ['provider' => 'backup', 'key' => 'backup-model', 'context_limit' => 8_000],
    ]);

    primary()->willThrow(new ContextOverflow('Prompt is too long', 'fake', 'primary-model'));

    $run = app(AgentRunner::class)->agent(agentWithFallback())->run('Hello');

    expect($run->state)->toBe(RunState::Failed)
        ->and($run->error_class)->toBe(ContextOverflow::class);
});

it('does not fail over on a failure another model would share', function (): void {
    primary()->willThrow(new ProviderRejectedRequest('Unsupported parameter', 'fake', 'primary-model'));
    backup()->willRespondWith('Never reached.');

    $run = app(AgentRunner::class)->agent(agentWithFallback())->run('Hello');

    // A malformed request is malformed everywhere. Failing over would waste a
    // second call and hide the actual problem.
    expect($run->state)->toBe(RunState::Failed)
        ->and($run->error_class)->toBe(ProviderRejectedRequest::class)
        ->and(AuditLog::query()->where('action', 'provider.failover')->count())->toBe(0);
});

it('fails with the last provider\'s reason when the chain is exhausted', function (): void {
    primary()->willThrow(new ProviderUnavailable('Primary is down', 'fake', 'primary-model'));
    backup()->willThrow(new ProviderUnavailable('Backup is down too', 'backup', 'backup-model'));

    $run = app(AgentRunner::class)->agent(agentWithFallback())->run('Hello');

    // Not "no model available" -- that sends an operator hunting through
    // config for a problem that has nothing to do with configuration.
    expect($run->state)->toBe(RunState::Failed)
        ->and($run->error_class)->toBe(ProviderUnavailable::class)
        ->and($run->error_message)->toBe('Backup is down too');
});

it('counts a real failure towards the provider\'s health', function (): void {
    config()->set('pandora.providers.health.failure_threshold', 1);

    primary()->willThrow(new ProviderUnavailable('Bad gateway', 'fake', 'primary-model'));
    backup()->willRespondWith('Fine.');

    app(AgentRunner::class)->agent(agentWithFallback())->run('Hello');

    $monitor = app(ProviderHealthMonitor::class);

    expect($monitor->isUsable('fake'))->toBeFalse()
        // And a working call is evidence too, so the backup is healthy.
        ->and($monitor->status('backup')->status)->toBe('healthy');
});

it('does not blame the provider for a request we got wrong', function (): void {
    config()->set('pandora.providers.health.failure_threshold', 1);

    primary()->willThrow(new ProviderRejectedRequest('Unsupported parameter', 'fake', 'primary-model'));

    app(AgentRunner::class)->agent(agentWithFallback())->run('Hello');

    // Counting our own bad request towards degradation would take a perfectly
    // healthy provider out of every fallback chain.
    expect(app(ProviderHealthMonitor::class)->isUsable('fake'))
        ->toBeTrue();
});
