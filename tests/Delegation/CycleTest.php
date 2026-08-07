<?php

declare(strict_types=1);

use Pandora\Audit\AuditLog;
use Pandora\Delegation\DelegationGuard;
use Pandora\Delegation\DelegationRefusal;
use Pandora\Runs\Run;
use Pandora\Tests\Support\MakesDelegations;
use Pandora\Tools\ToolExecution;

/**
 * Phase 6 acceptance criterion 7 — a delegation back into its own ancestry is
 * refused before the child run is created.
 *
 * The depth limit alone would terminate A -> B -> A. It would terminate it by
 * spending the entire tree budget on the way down, which is a refusal an
 * operator discovers on the invoice rather than in the trace. Refusing the
 * cycle outright costs one ancestor walk.
 */
uses(MakesDelegations::class);

beforeEach(function (): void {
    $this->registerDelegationTools();
    config()->set('pandora.delegation.max_depth', 5);
});

/**
 * A -> B -> A. The second hop is refused, and B keeps running.
 */
it('refuses a delegation back to an agent already in the ancestry', function (): void {
    $guard = app(DelegationGuard::class);

    $agentA = $this->makeAgent([
        'slug' => 'agent-a',
        'delegation_policy' => ['allow' => ['agent-b']],
    ]);
    $agentB = $this->makeAgent([
        'slug' => 'agent-b',
        'delegation_policy' => ['allow' => ['agent-a']],
    ]);

    $runA = $this->makeRun(['agent_id' => $agentA->getKey()]);
    $runB = $this->makeRun([
        'agent_id' => $agentB->getKey(),
        'parent_run_id' => $runA->getKey(),
        'delegation_depth' => 1,
    ]);

    $decision = $guard->decide($runB, $agentB, $agentA);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->refusal)->toBe(DelegationRefusal::CycleRefused)
        ->and($decision->reason)->toContain('already running higher up');
});

/**
 * An agent cannot delegate to itself.
 *
 * A run asking for a fresh copy of itself is asking for a loop with a longer
 * period, not for a different agent.
 */
it('refuses an agent delegating to itself', function (): void {
    $guard = app(DelegationGuard::class);

    $agent = $this->makeAgent([
        'slug' => 'self-caller',
        'delegation_policy' => ['allow' => ['self-caller']],
    ]);

    $run = $this->makeRun(['agent_id' => $agent->getKey()]);

    expect($guard->decide($run, $agent, $agent)->refusal)
        ->toBe(DelegationRefusal::CycleRefused);
});

/**
 * The check reaches past the immediate parent.
 *
 * A -> B -> C -> A. The cycle is three hops from where it is detected, so a
 * check that only compared against the caller would miss it entirely -- and
 * that is the shape a real cycle takes, because a direct A -> A is the one
 * somebody notices while writing the allowlist.
 */
it('walks the whole ancestry, not just the immediate parent', function (): void {
    $guard = app(DelegationGuard::class);

    $agentA = $this->makeAgent(['slug' => 'chain-a']);
    $agentB = $this->makeAgent(['slug' => 'chain-b']);
    $agentC = $this->makeAgent([
        'slug' => 'chain-c',
        'delegation_policy' => ['allow' => ['chain-a']],
    ]);

    $runA = $this->makeRun(['agent_id' => $agentA->getKey()]);
    $runB = $this->makeRun([
        'agent_id' => $agentB->getKey(),
        'parent_run_id' => $runA->getKey(),
        'delegation_depth' => 1,
    ]);
    $runC = $this->makeRun([
        'agent_id' => $agentC->getKey(),
        'parent_run_id' => $runB->getKey(),
        'delegation_depth' => 2,
    ]);

    expect($guard->decide($runC, $agentC, $agentA)->refusal)
        ->toBe(DelegationRefusal::CycleRefused);
});

/**
 * Two different agents are not a cycle, however deep the tree.
 *
 * The control. Without it a guard that refused everything would pass every
 * other test in this file.
 */
it('permits a deeper delegation that does not revisit an agent', function (): void {
    $guard = app(DelegationGuard::class);

    $agentA = $this->makeAgent(['slug' => 'fresh-a']);
    $agentB = $this->makeAgent([
        'slug' => 'fresh-b',
        'delegation_policy' => ['allow' => ['fresh-c']],
    ]);
    $agentC = $this->makeAgent(['slug' => 'fresh-c']);

    $runA = $this->makeRun(['agent_id' => $agentA->getKey()]);
    $runB = $this->makeRun([
        'agent_id' => $agentB->getKey(),
        'parent_run_id' => $runA->getKey(),
        'delegation_depth' => 1,
    ]);

    expect($guard->decide($runB, $agentB, $agentC)->allowed)->toBeTrue();
});

it('creates no child run and records delegation.cycle_refused as a warning', function (): void {
    // The parent is allowlisted to delegate to itself, end to end through a
    // real run rather than through the guard alone.
    $parent = $this->agent();
    $parent->forceFill([
        'tool_policy' => ['allow' => ['delegate_to_agent']],
        'delegation_policy' => ['allow' => [$parent->slug]],
    ])->save();

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall(agent: $parent->slug)])
        ->willRespondWith('I will do it myself.');

    $parentRun = $this->runParent();

    expect(Run::query()->count())->toBe(1)
        ->and($this->childOf($parentRun))->toBeNull();

    /** @var AuditLog $entry */
    $entry = AuditLog::query()->where('action', 'delegation.cycle_refused')->firstOrFail();

    expect($entry->severity)->toBe('warning');

    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()
        ->where('run_id', $parentRun->getKey())
        ->where('tool_name', 'delegate_to_agent')
        ->firstOrFail();

    expect($execution->result['content'])->toContain('would loop');
});
