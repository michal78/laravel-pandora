<?php

declare(strict_types=1);

use Pandora\Audit\AuditLog;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\RunCanceller;
use Pandora\Tests\Support\MakesDelegations;
use Pandora\Tools\Enums\ToolExecutionStatus;

/**
 * Phase 6 acceptance criterion 11 — cancellation flows down, never up.
 *
 * Down, because a cancelled parent has no use for its children's results and
 * every token they go on to spend is spent on a question nobody is waiting for
 * the answer to.
 *
 * Never up, because cancelling a delegate must not kill the conversation that
 * asked for it. Somebody withdrew a question; the person who asked it is still
 * entitled to be told so and to carry on.
 */
uses(MakesDelegations::class);

beforeEach(function (): void {
    $this->registerDelegationTools();
});

/**
 * A parent parked in `waiting_for_tool` is exactly where a delegating parent
 * sits, and it is the case the old code got wrong: children were cancelled only
 * on the draining path, so a run that finalised by another route left its child
 * running on behalf of a parent that no longer wanted the answer. This is the
 * regression test.
 *
 * `waiting_for_tool` itself drains rather than finalising immediately -- a call
 * is outstanding, and the run is entitled to wait for it to report.
 */
it('cancels a child of a parent waiting on a tool', function (): void {
    $parentAgent = $this->agent();
    $childAgent = $this->makeAgent(['slug' => 'cancelled-specialist']);

    $parent = $this->makeRun([
        'agent_id' => $parentAgent->getKey(),
        'state' => RunState::WaitingForTool->value,
    ]);
    $child = $this->makeRun([
        'agent_id' => $childAgent->getKey(),
        'parent_run_id' => $parent->getKey(),
        'delegation_depth' => 1,
        'state' => RunState::Pending->value,
    ]);

    app(RunCanceller::class)->cancel($parent, 'The user changed their mind.');

    expect($child->fresh()->state)->toBe(RunState::Cancelled)
        ->and($parent->fresh()->state)->toBe(RunState::Cancelling);
});

/**
 * A parent that holds nothing finalises at once, and still takes its child.
 */
it('cancels a child of a parent that finalises immediately', function (): void {
    $parentAgent = $this->agent();
    $childAgent = $this->makeAgent(['slug' => 'immediate-specialist']);

    $parent = $this->makeRun([
        'agent_id' => $parentAgent->getKey(),
        'state' => RunState::WaitingForUser->value,
    ]);
    $child = $this->makeRun([
        'agent_id' => $childAgent->getKey(),
        'parent_run_id' => $parent->getKey(),
        'delegation_depth' => 1,
        'state' => RunState::Pending->value,
    ]);

    app(RunCanceller::class)->cancel($parent, 'The user changed their mind.');

    expect($parent->fresh()->state)->toBe(RunState::Cancelled)
        ->and($child->fresh()->state)->toBe(RunState::Cancelled);
});

it('cancels a child of a parent that has to drain first', function (): void {
    $parentAgent = $this->agent();
    $childAgent = $this->makeAgent(['slug' => 'draining-specialist']);

    $parent = $this->makeRun([
        'agent_id' => $parentAgent->getKey(),
        'state' => RunState::Running->value,
    ]);
    $child = $this->makeRun([
        'agent_id' => $childAgent->getKey(),
        'parent_run_id' => $parent->getKey(),
        'delegation_depth' => 1,
        'state' => RunState::Pending->value,
    ]);

    app(RunCanceller::class)->cancel($parent);

    expect($parent->fresh()->state)->toBe(RunState::Cancelling)
        ->and($child->fresh()->state)->toBe(RunState::Cancelled);
});

/**
 * Transitively. A grandchild is as cancelled as a child.
 */
it('cancels the whole subtree, not just the first level', function (): void {
    $a = $this->makeAgent(['slug' => 'tree-a']);
    $b = $this->makeAgent(['slug' => 'tree-b']);
    $c = $this->makeAgent(['slug' => 'tree-c']);

    $root = $this->makeRun(['agent_id' => $a->getKey(), 'state' => RunState::Running->value]);
    $middle = $this->makeRun([
        'agent_id' => $b->getKey(),
        'parent_run_id' => $root->getKey(),
        'delegation_depth' => 1,
        'state' => RunState::WaitingForTool->value,
    ]);
    $leaf = $this->makeRun([
        'agent_id' => $c->getKey(),
        'parent_run_id' => $middle->getKey(),
        'delegation_depth' => 2,
        'state' => RunState::Pending->value,
    ]);

    app(RunCanceller::class)->cancel($root);

    // The middle run drains -- it has an outstanding call, namely the leaf.
    // The leaf holds nothing and finalises at once. Both are stopped, which is
    // what the criterion asks; only the route differs.
    expect($middle->fresh()->state)->toBe(RunState::Cancelling)
        ->and($middle->fresh()->isCancelRequested())->toBeTrue()
        ->and($leaf->fresh()->state)->toBe(RunState::Cancelled);
});

/** The direction that must NOT hold. */
it('never cancels a parent when a child is cancelled', function (): void {
    $parentAgent = $this->agent();
    $childAgent = $this->makeAgent(['slug' => 'upward-specialist']);

    $parent = $this->makeRun([
        'agent_id' => $parentAgent->getKey(),
        'state' => RunState::WaitingForTool->value,
    ]);
    $child = $this->makeRun([
        'agent_id' => $childAgent->getKey(),
        'parent_run_id' => $parent->getKey(),
        'delegation_depth' => 1,
        'state' => RunState::Running->value,
    ]);

    app(RunCanceller::class)->cancel($child, 'This delegate is no longer needed.');

    expect($child->fresh()->state)->toBe(RunState::Cancelling)
        ->and($parent->fresh()->state)->toBe(RunState::WaitingForTool);
});

/**
 * A cancelled child still closes the parent's tool call.
 *
 * Otherwise cancelling one delegate parks its parent forever -- the exact
 * outcome the "never upward" rule was supposed to avoid, arrived at from the
 * other side.
 */
it('closes the parent tool call when a child is cancelled on its own', function (): void {
    $parentAgent = $this->agent();
    $childAgent = $this->makeAgent(['slug' => 'closing-specialist', 'name' => 'Closing Specialist']);

    $parent = $this->makeRun([
        'agent_id' => $parentAgent->getKey(),
        'state' => RunState::WaitingForTool->value,
    ]);

    $execution = $this->makeExecution(
        $parent,
        'call_delegate',
        ToolExecutionStatus::Running,
        'delegate_to_agent',
    );

    $child = $this->makeRun([
        'agent_id' => $childAgent->getKey(),
        'parent_run_id' => $parent->getKey(),
        'delegation_depth' => 1,
        'state' => RunState::Pending->value,
        'delegated_tool_execution_id' => (string) $execution->getKey(),
    ]);

    app(RunCanceller::class)->cancel($child);

    $execution->refresh();

    expect($child->fresh()->state)->toBe(RunState::Cancelled)
        ->and($execution->status)->toBe(ToolExecutionStatus::Failed)
        ->and($execution->result['content'])->toContain('was cancelled');
});

it('records the propagation in the audit log', function (): void {
    $parentAgent = $this->agent();
    $childAgent = $this->makeAgent(['slug' => 'audited-specialist']);

    $parent = $this->makeRun([
        'agent_id' => $parentAgent->getKey(),
        'state' => RunState::Running->value,
    ]);
    $child = $this->makeRun([
        'agent_id' => $childAgent->getKey(),
        'parent_run_id' => $parent->getKey(),
        'delegation_depth' => 1,
        'state' => RunState::Pending->value,
    ]);

    app(RunCanceller::class)->cancel($parent);

    /** @var AuditLog $entry */
    $entry = AuditLog::query()->where('action', 'run.cancellation_propagated')->firstOrFail();

    expect($entry->target_id)->toBe((string) $child->getKey())
        ->and($entry->run_id)->toBe((string) $parent->getKey());
});

it('leaves an already-finished child alone', function (): void {
    $parentAgent = $this->agent();
    $childAgent = $this->makeAgent(['slug' => 'finished-specialist']);

    $parent = $this->makeRun([
        'agent_id' => $parentAgent->getKey(),
        'state' => RunState::Running->value,
    ]);
    $child = $this->makeRun([
        'agent_id' => $childAgent->getKey(),
        'parent_run_id' => $parent->getKey(),
        'delegation_depth' => 1,
        'state' => RunState::Completed->value,
        'output' => 'Already answered.',
    ]);

    app(RunCanceller::class)->cancel($parent);

    expect($child->fresh()->state)->toBe(RunState::Completed)
        ->and($child->fresh()->output)->toBe('Already answered.');
});
