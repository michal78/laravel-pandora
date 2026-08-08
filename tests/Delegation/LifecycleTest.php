<?php

declare(strict_types=1);

use Pandora\Audit\AuditLog;
use Pandora\Messages\Enums\MessageRole;
use Pandora\Messages\Message;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Enums\RunStepType;
use Pandora\Runs\RunStateMachine;
use Pandora\Runs\RunStep;
use Pandora\Tests\Support\MakesDelegations;
use Pandora\Tools\Enums\ToolExecutionStatus;
use Pandora\Tools\ToolExecution;

/**
 * Phase 6 acceptance criterion 10 — the parent waits, and then resumes with the
 * child's answer as a tool result.
 *
 * A delegation is the one tool call that is not finished when the tool returns.
 * The call stays open, the parent holds no job while it waits, and the child's
 * terminal state is what closes the call and wakes the parent -- the same shape
 * as an approval pause, for the same reason.
 */
uses(MakesDelegations::class);

beforeEach(function (): void {
    $this->registerDelegationTools();
});

it('appends the child answer as a tool result the parent model reads', function (): void {
    $this->makeDelegationPair();

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('The order shipped on Tuesday from the Leeds depot.')
        ->willRespondWith('It shipped on Tuesday.');

    $parentRun = $this->runParent();

    /** @var Message $toolResult */
    $toolResult = Message::query()
        ->where('run_id', $parentRun->getKey())
        ->where('role', MessageRole::Tool->value)
        ->firstOrFail();

    expect($toolResult->content)->toContain('Leeds depot')
        ->and($toolResult->tool_call_id)->toBe('call_delegate');
});

it('closes the parent tool call only when the child ends', function (): void {
    $this->makeDelegationPair();

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('Child answer.')
        ->willRespondWith('Parent answer.');

    $parentRun = $this->runParent();

    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()
        ->where('run_id', $parentRun->getKey())
        ->where('tool_name', 'delegate_to_agent')
        ->firstOrFail();

    expect($execution->status)->toBe(ToolExecutionStatus::Succeeded)
        ->and($execution->finished_at)->not->toBeNull()
        ->and($execution->result['data']['child_run_id'])
        ->toBe((string) $this->childOf($parentRun)->getKey());
});

it('resumes the parent so it answers with what the child found', function (): void {
    $this->makeDelegationPair();

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('Tuesday.')
        ->willRespondWith('The specialist says Tuesday.');

    $parentRun = $this->runParent();

    expect($parentRun->state)->toBe(RunState::Completed)
        ->and($parentRun->output)->toBe('The specialist says Tuesday.')
        // Two turns: the one that delegated, and the one that answered.
        ->and($parentRun->iterations)->toBe(2);
});

it('records the delegation on the parent trace, started and finished', function (): void {
    $this->makeDelegationPair();

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('Answer.')
        ->willRespondWith('Done.');

    $parentRun = $this->runParent();

    $steps = RunStep::query()
        ->where('run_id', $parentRun->getKey())
        ->where('type', RunStepType::Delegation->value)
        ->orderBy('sequence')
        ->get();

    expect($steps)->toHaveCount(2)
        ->and($steps[0]->label)->toContain('Delegated to Specialist')
        ->and($steps[1]->label)->toContain('finished')
        ->and($steps[1]->status->value)->toBe('succeeded');
});

it('audits the delegation starting and completing', function (): void {
    $this->makeDelegationPair();

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('Answer.')
        ->willRespondWith('Done.');

    $parentRun = $this->runParent();
    $child = $this->childOf($parentRun);

    /** @var AuditLog $started */
    $started = AuditLog::query()->where('action', 'delegation.started')->firstOrFail();
    /** @var AuditLog $completed */
    $completed = AuditLog::query()->where('action', 'delegation.completed')->firstOrFail();

    expect($started->target_id)->toBe((string) $child->getKey())
        ->and($started->run_id)->toBe((string) $parentRun->getKey())
        ->and($completed->target_id)->toBe((string) $child->getKey())
        ->and($completed->metadata['state'])->toBe('completed');
});

/**
 * A failed child still answers.
 *
 * A parent that is never answered does not fail -- it waits until its own
 * deadline, which is the worst of both outcomes: slow and uninformative.
 */
it('answers the parent when the child fails rather than leaving it parked', function (): void {
    // Driven through the state machine rather than through a thrown provider
    // error, because what is under test is the completer's failure branch --
    // that a child ending badly still closes the parent's call. Reaching it via
    // a provider exception would test the failover machinery on the way and
    // leave this assertion at the mercy of retry counts.
    $parentAgent = $this->agent();
    $childAgent = $this->makeAgent(['slug' => 'failing-specialist', 'name' => 'Failing Specialist']);

    $parentRun = $this->makeRun([
        'agent_id' => $parentAgent->getKey(),
        'state' => RunState::WaitingForTool->value,
    ]);

    $execution = $this->makeExecution(
        $parentRun,
        'call_delegate',
        ToolExecutionStatus::Running,
        'delegate_to_agent',
    );

    $child = $this->makeRun([
        'agent_id' => $childAgent->getKey(),
        'parent_run_id' => $parentRun->getKey(),
        'delegation_depth' => 1,
        'state' => RunState::Running->value,
        'delegated_tool_execution_id' => (string) $execution->getKey(),
    ]);

    app(RunStateMachine::class)->transition($child, RunState::Failed, [
        'error_class' => RuntimeException::class,
        'error_message' => 'the specialist model fell over',
    ]);

    $execution->refresh();

    expect($execution->status)->toBe(ToolExecutionStatus::Failed)
        ->and($execution->result['content'])->toContain('could not complete the work')
        ->and($execution->result['content'])->toContain('fell over')
        // And where an operator reads it. A delegation that ends badly closed
        // this row with an empty error column until the walkthrough found a
        // failed child sitting behind a call that still said "waiting".
        ->and($execution->error_message)->toContain('fell over')
        ->and($execution->finished_at)->not->toBeNull();
});

/**
 * A child that finished without saying anything still says something.
 *
 * An empty tool result is a model's invitation to invent what the delegate
 * probably meant.
 */
it('substitutes a sentence when the child produced no output', function (): void {
    $this->makeDelegationPair();

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('')
        ->willRespondWith('Nothing came back.');

    $parentRun = $this->runParent();

    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()
        ->where('run_id', $parentRun->getKey())
        ->where('tool_name', 'delegate_to_agent')
        ->firstOrFail();

    expect($execution->result['content'])->toContain('without producing an answer');
});
