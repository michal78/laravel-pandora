<?php

declare(strict_types=1);

use Pandora\Audit\AuditLog;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Run;
use Pandora\Tests\Support\MakesDelegations;
use Pandora\Tools\Enums\ToolExecutionStatus;
use Pandora\Tools\ToolExecution;

/**
 * Phase 6 acceptance criterion 5 — who may be asked.
 *
 * The same rule as the tool allowlist, because it is the same problem. An agent
 * reachable by omission is an agent nobody chose to expose, and "any enabled
 * agent" is a graph in which one weak node is every node.
 */
uses(MakesDelegations::class);

beforeEach(function (): void {
    $this->registerDelegationTools();
});

it('starts with an empty delegation allowlist', function (): void {
    expect($this->makeAgent()->delegatableAgents())->toBe([]);
});

it('refuses an agent that is not on the allowlist, and starts no child run', function (): void {
    $this->makeAgent(['slug' => 'stranger', 'name' => 'Stranger']);

    // The parent may delegate -- to `specialist`, which is not who it asks for.
    $this->makeDelegationPair(childSlug: 'specialist');

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall(agent: 'stranger')])
        ->willRespondWith('I could not hand that over.');

    $parentRun = $this->runParent();

    expect(Run::query()->count())->toBe(1)
        ->and($this->childOf($parentRun))->toBeNull()
        ->and($parentRun->state)->toBe(RunState::Completed);
});

it('tells the model why, as a tool error, and keeps the parent running', function (): void {
    $this->makeAgent(['slug' => 'stranger', 'name' => 'Stranger']);
    $this->makeDelegationPair(childSlug: 'specialist');

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall(agent: 'stranger')])
        ->willRespondWith('I am not able to pass that on.');

    $parentRun = $this->runParent();

    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()
        ->where('run_id', $parentRun->getKey())
        ->where('tool_name', 'delegate_to_agent')
        ->firstOrFail();

    expect($execution->status)->toBe(ToolExecutionStatus::Failed)
        ->and($execution->result['content'])->toContain('not permitted to delegate')
        // And the same sentence where an OPERATOR looks. A tool that refuses
        // rather than throws is still a failure, and a failed call whose error
        // is empty is the reason a live delegation refusal read as "denied,
        // no reason given".
        ->and($execution->error_message)->toContain('not permitted to delegate')
        // The refusal did not end the run. A bounded refusal that looked like
        // an outage would teach operators to widen the allowlist to stop the
        // alerts.
        ->and($parentRun->state)->toBe(RunState::Completed)
        ->and($parentRun->output)->toBe('I am not able to pass that on.');
});

it('records a warning-severity audit entry naming the refusal', function (): void {
    $this->makeAgent(['slug' => 'stranger', 'name' => 'Stranger']);
    $this->makeDelegationPair(childSlug: 'specialist');

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall(agent: 'stranger')])
        ->willRespondWith('No.');

    $this->runParent();

    /** @var AuditLog $entry */
    $entry = AuditLog::query()->where('action', 'delegation.denied')->firstOrFail();

    expect($entry->severity)->toBe('warning')
        ->and($entry->metadata['refusal'])->toBe('not_allowed')
        ->and($entry->metadata['target_agent'])->toBe('stranger');
});

it('refuses an agent that does not exist without saying whether it exists', function (): void {
    $this->makeDelegationPair();

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall(agent: 'no-such-agent')])
        ->willRespondWith('Could not.');

    $parentRun = $this->runParent();

    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()
        ->where('run_id', $parentRun->getKey())
        ->where('tool_name', 'delegate_to_agent')
        ->firstOrFail();

    expect($execution->result['content'])->toContain('no agent by that name available to you')
        ->and(Run::query()->count())->toBe(1);
});

it('refuses a disabled agent even when it is on the allowlist', function (): void {
    [, $child] = $this->makeDelegationPair();
    $child->forceFill(['enabled' => false])->save();

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('Could not.');

    $parentRun = $this->runParent();

    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()
        ->where('run_id', $parentRun->getKey())
        ->where('tool_name', 'delegate_to_agent')
        ->firstOrFail();

    expect($execution->result['content'])->toContain('disabled')
        ->and(Run::query()->count())->toBe(1);
});

/**
 * Naming an agent as delegable is not a grant of that agent's tools.
 *
 * The allowlist decides who may be ASKED. The intersection decides what the
 * answer may do. Conflating them is exactly the escalation this phase exists to
 * prevent, so it is worth one test that says so on its own.
 */
it('does not hand the parent the child agent tools merely by allowlisting it', function (): void {
    [$parent] = $this->makeDelegationPair(
        parentTools: ['delegate_to_agent'],
        childTools: ['refund_order'],
    );

    expect($parent->delegatableAgents())->toBe(['specialist'])
        ->and($parent->allowedTools())->toBe(['delegate_to_agent'])
        ->and($parent->allowedTools())->not->toContain('refund_order');
});
