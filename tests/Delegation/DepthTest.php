<?php

declare(strict_types=1);

use Pandora\Agents\Agent;
use Pandora\Audit\AuditLog;
use Pandora\Delegation\DelegationGuard;
use Pandora\Delegation\DelegationRefusal;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Run;
use Pandora\Tests\Support\MakesDelegations;
use Pandora\Tools\Enums\ToolExecutionStatus;
use Pandora\Tools\ToolExecution;

/**
 * Phase 6 acceptance criterion 6 — how deep a tree may go, and what happens at
 * the bottom.
 *
 * The limit denies the TOOL and the parent carries on. It does not fail the
 * run. That is a deliberate choice with an operational reason behind it:
 * failing the run makes a bounded refusal look like an outage, and an operator
 * who keeps seeing runs fail at the depth limit will raise the limit to stop
 * the failures -- which is exactly the wrong lesson to teach.
 */
uses(MakesDelegations::class);

beforeEach(function (): void {
    $this->registerDelegationTools();
});

it('permits delegation up to the configured depth', function (): void {
    config()->set('pandora.delegation.max_depth', 1);
    $this->makeDelegationPair();

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('Specialist answer.')
        ->willRespondWith('Done.');

    $parentRun = $this->runParent();

    expect($this->childOf($parentRun)->delegation_depth)->toBe(1)
        ->and($parentRun->state)->toBe(RunState::Completed);
});

it('denies the tool at one hop past the limit and starts no run', function (): void {
    config()->set('pandora.delegation.max_depth', 0);
    $this->makeDelegationPair();

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('I will handle it myself.');

    $parentRun = $this->runParent();

    expect(Run::query()->count())->toBe(1)
        ->and($this->childOf($parentRun))->toBeNull();
});

/**
 * The heart of the criterion: the tool fails, the RUN does not.
 */
it('leaves the parent running with a tool error rather than failing it', function (): void {
    config()->set('pandora.delegation.max_depth', 0);
    $this->makeDelegationPair();

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('I looked at this myself instead.');

    $parentRun = $this->runParent();

    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()
        ->where('run_id', $parentRun->getKey())
        ->where('tool_name', 'delegate_to_agent')
        ->firstOrFail();

    expect($execution->status)->toBe(ToolExecutionStatus::Failed)
        ->and($parentRun->state)->toBe(RunState::Completed)
        ->and($parentRun->output)->toBe('I looked at this myself instead.')
        ->and($parentRun->error_class)->toBeNull();
});

/**
 * The refusal tells the model what to do next.
 *
 * "Denied" on its own produces an agent that retries the same call until its
 * iteration limit stops it. The sentence has to close that door.
 */
it('tells the model the limit and what to do instead', function (): void {
    config()->set('pandora.delegation.max_depth', 0);
    $this->makeDelegationPair();

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('Handled.');

    $parentRun = $this->runParent();

    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()
        ->where('run_id', $parentRun->getKey())
        ->where('tool_name', 'delegate_to_agent')
        ->firstOrFail();

    expect($execution->result['content'])
        ->toContain('Delegation is limited to 0 levels')
        ->toContain('Do the remaining work yourself');
});

it('records delegation.depth_exceeded as a warning', function (): void {
    config()->set('pandora.delegation.max_depth', 0);
    $this->makeDelegationPair();

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('Handled.');

    $this->runParent();

    /** @var AuditLog $entry */
    $entry = AuditLog::query()->where('action', 'delegation.depth_exceeded')->firstOrFail();

    expect($entry->severity)->toBe('warning')
        ->and($entry->metadata['delegation_depth'])->toBe(0);
});

/**
 * The limit counts the whole tree, not one hop.
 *
 * A grandchild at depth 2 under a limit of 1 is refused even though its own
 * parent delegated successfully -- which is the case a per-hop check would get
 * wrong, and the case that matters, because it is how a tree grows without any
 * single delegation looking unreasonable.
 */
it('counts depth from the root of the tree', function (): void {
    config()->set('pandora.delegation.max_depth', 1);

    $guard = app(DelegationGuard::class);

    $rootAgent = $this->makeAgent(['slug' => 'root-agent']);
    $middleAgent = $this->makeAgent([
        'slug' => 'middle-agent',
        'delegation_policy' => ['allow' => ['leaf-agent']],
    ]);
    $leafAgent = $this->makeAgent(['slug' => 'leaf-agent']);

    $rootRun = $this->makeRun(['agent_id' => $rootAgent->getKey()]);
    $middleRun = $this->makeRun([
        'agent_id' => $middleAgent->getKey(),
        'parent_run_id' => $rootRun->getKey(),
        'delegation_depth' => 1,
    ]);

    $decision = $guard->decide($middleRun, $middleAgent, $leafAgent);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->refusal)->toBe(DelegationRefusal::DepthExceeded);
});

it('honours a raised limit', function (): void {
    config()->set('pandora.delegation.max_depth', 5);

    $guard = app(DelegationGuard::class);

    $parentAgent = $this->makeAgent([
        'slug' => 'deep-parent',
        'delegation_policy' => ['allow' => ['deep-child']],
    ]);
    $childAgent = $this->makeAgent(['slug' => 'deep-child']);

    /** @var Agent $parentAgent */
    $run = $this->makeRun(['agent_id' => $parentAgent->getKey(), 'delegation_depth' => 4]);

    expect($guard->decide($run, $parentAgent, $childAgent)->allowed)->toBeTrue();
});
