<?php

declare(strict_types=1);

use Pandora\Approvals\Approval;
use Pandora\Approvals\ApprovalManager;
use Pandora\Approvals\Enums\ApprovalStatus;
use Pandora\Core\Actor\ActorContext;
use Pandora\Exceptions\ApprovalNotPending;
use Pandora\Jobs\ExecuteToolCall;
use Pandora\Jobs\ResumeApprovedRun;
use Pandora\Providers\Data\ToolCall;
use Pandora\Runs\Run;
use Pandora\Runs\RunCanceller;
use Pandora\Tests\Fixtures\Tools\RefundOrderTool;
use Pandora\Tests\Support\MakesTools;
use Pandora\Tools\ToolExecution;

/**
 * Phase 2 acceptance criteria 16 and 17 — threat T14.
 *
 * An approval is consumed exactly once, and an approved call is re-authorized
 * when it actually runs. Between a decision and its execution a run may have
 * waited days: permissions change, records change, and an approval that was
 * sound on Monday is not thereby sound on Thursday.
 */
uses(MakesTools::class);

beforeEach(function (): void {
    RefundOrderTool::$refunds = [];
    $this->registerTools([RefundOrderTool::class]);
    $this->agentAllows(['refund_order']);

    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'refund_order', [
            'reference' => 'ORD-1234',
            'amount_minor' => 4200,
        ])])
        ->willRespondWith('Done.');

    $this->pausedRun = $this->runToolAgent('Refund order ORD-1234.');
    $this->approval = Approval::query()->where('run_id', $this->pausedRun->getKey())->firstOrFail();
});

it('consumes an approval exactly once when two approvers race', function (): void {
    $manager = app(ApprovalManager::class);
    $actor = ActorContext::forUser($this->toolUser());

    $manager->approve($this->approval, $actor, authorize: false);

    // The second approver's click, arriving a moment later.
    expect(fn () => $manager->approve($this->approval->fresh(), $actor, authorize: false))
        ->toThrow(ApprovalNotPending::class);

    // One decision, one refund. Not two.
    expect(RefundOrderTool::$refunds)->toHaveCount(1);
});

it('refuses a denial of an approval already approved', function (): void {
    $manager = app(ApprovalManager::class);

    $manager->approve($this->approval, null, authorize: false);

    expect(fn () => $manager->deny($this->approval->fresh(), null, authorize: false))
        ->toThrow(ApprovalNotPending::class);

    expect(Approval::query()->findOrFail($this->approval->getKey())->status)
        ->toBe(ApprovalStatus::Approved);
});

it('reports what the approval was already resolved as', function (): void {
    $manager = app(ApprovalManager::class);
    $manager->deny($this->approval, null, authorize: false);

    try {
        $manager->approve($this->approval->fresh(), null, authorize: false);
        $this->fail('Expected ApprovalNotPending.');
    } catch (ApprovalNotPending $e) {
        expect($e->status)->toBe(ApprovalStatus::Denied)
            ->and($e->userMessage())->toContain('already been resolved');
    }
});

it('executes the tool once even when the resume job is delivered twice', function (): void {
    app(ApprovalManager::class)->approve($this->approval, null, authorize: false);

    expect(RefundOrderTool::$refunds)->toHaveCount(1);

    // A duplicate delivery of the same job, the way an at-least-once queue
    // will eventually give you.
    ResumeApprovedRun::dispatchSync(
        (string) $this->approval->getKey(),
        $this->pausedRun->tenant_id,
        $this->pausedRun->actor_type,
        $this->pausedRun->actor_id,
    );

    expect(RefundOrderTool::$refunds)->toHaveCount(1);
});

/**
 * A redelivery has to carry what the queue would have carried.
 *
 * `ExecuteToolCall` restores the tenant and the actor from its own payload
 * before it does anything, and a real at-least-once redelivery brings both.
 * Dispatching without them was the whole reason this test used to pass: the
 * actor came back null, `RefundOrderTool::authorize()` refuses when there is
 * no user, and the gatekeeper denied the second call. It was proving that a
 * job stripped of its actor is denied — true, and nothing to do with
 * idempotency. Every guard in `ExecuteToolCall` could be deleted and it stayed
 * green (verified 2026-08-19); dispatched faithfully, the same removal refunds
 * the customer twice.
 */
function redeliver(ToolExecution $execution, Run $run): void
{
    ExecuteToolCall::dispatchSync(
        (string) $execution->getKey(),
        $run->tenant_id,
        true,
        $run->actor_type,
        $run->actor_id,
    );
}

it('does not re-apply a tool when its own job is retried', function (): void {
    app(ApprovalManager::class)->approve($this->approval, null, authorize: false);

    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()->where('run_id', $this->pausedRun->getKey())->firstOrFail();

    redeliver($execution, $this->pausedRun);
    redeliver($execution, $this->pausedRun);

    expect(RefundOrderTool::$refunds)->toHaveCount(1);
});

/**
 * The redelivery that arrives while the run is still going.
 *
 * Two guards stop a repeated execution — the terminal execution row at the top
 * of `ExecuteToolCall::handle()`, and the terminal run below it — and in the
 * test above they cover for each other, so removing either one alone leaves it
 * green. Neither is thereby proved.
 *
 * This is the case where only the first of them stands: a run with two parked
 * calls, one approved and finished, the other still waiting on a human. The
 * run is `waiting_for_tool` rather than terminal, so a duplicate delivery of
 * the finished call gets past the run check and the execution row is the only
 * thing between an at-least-once queue and a second refund. It is also the
 * ordinary shape of the problem: a queue redelivers while work is in flight,
 * not after everything has settled.
 */
it('does not re-apply a finished call when the run is still waiting on another', function (): void {
    RefundOrderTool::$refunds = [];

    // The paused run from `beforeEach` left the script mid-way, on the reply
    // it never reached. Appending to it would hand this run that reply instead
    // of the tool calls it is about.
    $this->fakeProvider()
        ->reset()
        ->willRequestTools([
            new ToolCall('call_a', 'refund_order', ['reference' => 'ORD-A', 'amount_minor' => 100]),
            new ToolCall('call_b', 'refund_order', ['reference' => 'ORD-B', 'amount_minor' => 200]),
        ])
        ->willRespondWith('Done.');

    $run = $this->runToolAgent('Refund both orders.');

    $approvals = Approval::query()->where('run_id', $run->getKey())->orderBy('id')->get();
    expect($approvals)->toHaveCount(2);

    // One approved, one still parked.
    app(ApprovalManager::class)->approve($approvals[0], null, authorize: false);

    /** @var ToolExecution $finished */
    $finished = ToolExecution::query()->findOrFail($approvals[0]->tool_execution_id);

    expect(RefundOrderTool::$refunds)->toHaveCount(1)
        ->and($finished->status->isTerminal())->toBeTrue()
        // The run has not ended: the second call is still waiting on a human.
        ->and($run->fresh()->state->isTerminal())->toBeFalse();

    redeliver($finished, $run);

    expect(RefundOrderTool::$refunds)->toHaveCount(1);
});

it('re-authorizes at execution time, so a revoked permission still stops the call', function (): void {
    // Approved on Monday by someone with the authority. By Thursday, when the
    // job finally runs, the actor may no longer act at all.
    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()->where('run_id', $this->pausedRun->getKey())->firstOrFail();

    $this->agentAllows([]);

    app(ApprovalManager::class)->approve($this->approval, null, authorize: false);

    expect(RefundOrderTool::$refunds)->toBe([])
        ->and(ToolExecution::query()->findOrFail($execution->getKey())->status->value)
        ->toBe('denied');
});

it('says which layer denied the call at execution time, and why', function (): void {
    // The row was created by a decision that ALLOWED the call, so it carries
    // `decided_by: tool` and no reason. If the late denial does not overwrite
    // both, an operator reading the denied row sees the shape of a permitted
    // one and is told nothing.
    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()->where('run_id', $this->pausedRun->getKey())->firstOrFail();

    $this->agentAllows([]);

    app(ApprovalManager::class)->approve($this->approval, null, authorize: false);

    $denied = ToolExecution::query()->findOrFail($execution->getKey());

    expect($denied->decided_by)->toBe('agent')
        ->and($denied->decision_reason)->toContain('may not use [refund_order]');
});

it('re-validates at execution time, so tampered arguments cannot slip through', function (): void {
    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()->where('run_id', $this->pausedRun->getKey())->firstOrFail();

    // Somebody with database access edits the parked row between the decision
    // and its execution. The tool's own rules still apply.
    $execution->forceFill(['arguments' => ['reference' => 'ORD-1234', 'amount_minor' => 999999999]])->save();

    app(ApprovalManager::class)->approve($this->approval, null, authorize: false);

    expect(RefundOrderTool::$refunds)->toBe([])
        ->and(ToolExecution::query()->findOrFail($execution->getKey())->status->value)
        ->toBe('denied');
});

/**
 * A cancelled run stays cancelled, whatever the approvals page still shows.
 *
 * The stale-button case: an operator stops a run, and someone who still had
 * the approvals page open presses Approve on the request it was parked on.
 * `ResumeApprovedRun` checks the run is not terminal before it does anything,
 * and nothing in the suite reached that check — removing it left every
 * cancellation and approval test green (verified 2026-08-19).
 *
 * `ExecuteToolCall` would still refuse to run the tool, so no refund is issued
 * either way and asserting only that proves nothing. What the guard actually
 * decides is whether the resume touches the run at all: without it the parked
 * call is dragged back to `pending`, dispatched, and closed again as cancelled
 * — three writes and a trace entry on a run that ended, describing work that
 * never happened.
 */
it('does nothing when the run was cancelled before the approval was answered', function (): void {
    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()->where('run_id', $this->pausedRun->getKey())->firstOrFail();

    $cancelled = app(RunCanceller::class)->cancel($this->pausedRun, 'Operator pressed stop.');
    expect($cancelled->state->isTerminal())->toBeTrue();

    app(ApprovalManager::class)->approve($this->approval->fresh(), null, authorize: false);

    expect(RefundOrderTool::$refunds)->toBe([])
        // Untouched: still parked exactly as the cancellation left it.
        ->and(ToolExecution::query()->findOrFail($execution->getKey())->status)
        ->toBe($execution->status)
        ->and(Run::query()->findOrFail($this->pausedRun->getKey())->state->isTerminal())
        ->toBeTrue();
});
