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
    ResumeApprovedRun::dispatchSync((string) $this->approval->getKey());

    expect(RefundOrderTool::$refunds)->toHaveCount(1);
});

it('does not re-apply a tool when its own job is retried', function (): void {
    app(ApprovalManager::class)->approve($this->approval, null, authorize: false);

    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()->where('run_id', $this->pausedRun->getKey())->firstOrFail();

    ExecuteToolCall::dispatchSync((string) $execution->getKey());
    ExecuteToolCall::dispatchSync((string) $execution->getKey());

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
