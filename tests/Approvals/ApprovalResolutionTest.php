<?php

declare(strict_types=1);

use Pandora\Pandora\Approvals\Approval;
use Pandora\Pandora\Approvals\ApprovalManager;
use Pandora\Pandora\Approvals\Enums\ApprovalStatus;
use Pandora\Pandora\Core\Actor\ActorContext;
use Pandora\Pandora\Exceptions\ApprovalExpired;
use Pandora\Pandora\Messages\Enums\MessageRole;
use Pandora\Pandora\Messages\Message;
use Pandora\Pandora\Providers\Data\ToolCall;
use Pandora\Pandora\Runs\Enums\RunState;
use Pandora\Pandora\Runs\Enums\RunStepType;
use Pandora\Pandora\Runs\Run;
use Pandora\Pandora\Tests\Fixtures\Tools\RefundOrderTool;
use Pandora\Pandora\Tests\Support\MakesTools;
use Pandora\Pandora\Tools\Enums\ToolExecutionStatus;
use Pandora\Pandora\Tools\ToolExecution;

/**
 * Phase 2 acceptance criteria 14, 15 and 19 — what a decision does.
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

it('executes the approved call with the arguments that were approved', function (): void {
    app(ApprovalManager::class)->approve(
        $this->approval,
        ActorContext::forUser($this->toolUser()),
        authorize: false,
    );

    expect(RefundOrderTool::$refunds)->toBe([['reference' => 'ORD-1234', 'amount' => 4200]])
        ->and(Run::query()->findOrFail($this->pausedRun->getKey())->state)->toBe(RunState::Completed);
});

it('records who decided, and when', function (): void {
    $user = $this->toolUser();

    $approved = app(ApprovalManager::class)->approve(
        $this->approval,
        ActorContext::forUser($user),
        comment: 'Checked with the customer.',
        authorize: false,
    );

    expect($approved->status)->toBe(ApprovalStatus::Approved)
        ->and($approved->resolved_by_id)->toBe((string) $user->getKey())
        ->and($approved->resolved_at)->not->toBeNull()
        ->and($approved->comment)->toBe('Checked with the customer.');

    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()->where('run_id', $this->pausedRun->getKey())->firstOrFail();

    expect($execution->approver_id)->toBe((string) $user->getKey());
});

it('lets the run continue after a denial rather than failing it', function (): void {
    $this->fakeProvider()->reset()->willRespondWith('I could not issue that refund.');

    app(ApprovalManager::class)->deny(
        $this->approval,
        ActorContext::forUser($this->toolUser()),
        comment: 'Outside the returns window.',
        authorize: false,
    );

    /** @var Run $run */
    $run = Run::query()->findOrFail($this->pausedRun->getKey());

    expect(RefundOrderTool::$refunds)->toBe([])
        ->and($run->state)->toBe(RunState::Completed)
        ->and($run->output)->toBe('I could not issue that refund.');
});

it('tells the model that a human declined, and why', function (): void {
    $this->fakeProvider()->reset()->willRespondWith('Understood.');

    app(ApprovalManager::class)->deny(
        $this->approval,
        ActorContext::forUser($this->toolUser()),
        comment: 'Outside the returns window.',
        authorize: false,
    );

    /** @var Message $toolMessage */
    $toolMessage = Message::query()
        ->where('run_id', $this->pausedRun->getKey())
        ->where('role', MessageRole::Tool->value)
        ->firstOrFail();

    expect($toolMessage->content)->toContain('declined')
        ->and($toolMessage->content)->toContain('returns window');
});

it('marks the execution denied rather than merely skipped', function (): void {
    $this->fakeProvider()->reset()->willRespondWith('Understood.');

    app(ApprovalManager::class)->deny($this->approval, null, authorize: false);

    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()->where('run_id', $this->pausedRun->getKey())->firstOrFail();

    expect($execution->status)->toBe(ToolExecutionStatus::Denied)
        ->and($execution->finished_at)->not->toBeNull();
});

it('traces the response next to the request', function (): void {
    app(ApprovalManager::class)->approve($this->approval, null, authorize: false);

    $step = Run::query()->findOrFail($this->pausedRun->getKey())
        ->steps()
        ->where('type', RunStepType::ApprovalResponse->value)
        ->firstOrFail();

    expect($step->payload['status'])->toBe('approved')
        ->and($step->label)->toContain('Approved');
});

it('fails the run with a specific reason when nobody responds in time', function (): void {
    $this->approval->forceFill(['expires_at' => now()->subMinute()])->save();

    expect(app(ApprovalManager::class)->expireOverdue())->toBe(1);

    /** @var Run $run */
    $run = Run::query()->findOrFail($this->pausedRun->getKey());

    expect($run->state)->toBe(RunState::Failed)
        ->and($run->error_class)->toBe(ApprovalExpired::class)
        ->and($run->error_message)->toContain('expired')
        ->and(RefundOrderTool::$refunds)->toBe([]);
});

it('treats approval of an already-expired request as an expiry, not an approval', function (): void {
    // The window closed while the decision sat in a queue. The button said
    // approve; the clock says otherwise, and the clock wins.
    $this->approval->forceFill(['expires_at' => now()->subMinute()])->save();

    $resolved = app(ApprovalManager::class)->approve($this->approval, null, authorize: false);

    expect($resolved->status)->toBe(ApprovalStatus::Expired)
        ->and(RefundOrderTool::$refunds)->toBe([]);
});
