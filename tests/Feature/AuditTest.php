<?php

declare(strict_types=1);

use Pandora\Agents\AgentRunner;
use Pandora\Audit\AuditLog;
use Pandora\Exceptions\Provider\ProviderUnavailable;
use Pandora\Tests\Support\MakesRuns;

uses(MakesRuns::class);

it('audits a run from start to completion', function (): void {
    $this->fakeProvider()->willRespondWith('Done.');

    $conversation = $this->makeConversation();

    $run = app(AgentRunner::class)
        ->agent($conversation->agent)
        ->inConversation($conversation)
        ->run('Hello');

    $actions = AuditLog::query()->where('run_id', $run->getKey())->pluck('action')->all();

    expect($actions)->toContain('run.started')
        ->and($actions)->toContain('run.completed');
});

it('records a failed run in the audit trail', function (): void {
    $this->fakeProvider()->willThrow(
        new ProviderUnavailable('down', 'fake'),
    );

    $conversation = $this->makeConversation();

    $run = app(AgentRunner::class)
        ->agent($conversation->agent)
        ->inConversation($conversation)
        ->run('Hello');

    $log = AuditLog::query()->where('run_id', $run->getKey())->where('action', 'run.failed')->first();

    expect($log)->not->toBeNull()
        ->and($log->severity)->toBe('error');
});

it('threads one correlation id through every record of a run', function (): void {
    $this->fakeProvider()->willRespondWith('Done.');

    $conversation = $this->makeConversation();

    $run = app(AgentRunner::class)
        ->agent($conversation->agent)
        ->inConversation($conversation)
        ->run('Hello');

    $correlations = AuditLog::query()
        ->where('run_id', $run->getKey())
        ->pluck('correlation_id')
        ->unique();

    expect($correlations)->toHaveCount(1)
        ->and($correlations->first())->toBe($run->correlation_id);
});
