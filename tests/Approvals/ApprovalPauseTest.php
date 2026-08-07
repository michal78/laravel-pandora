<?php

declare(strict_types=1);

use Pandora\Approvals\Approval;
use Pandora\Approvals\ApprovalManager;
use Pandora\Approvals\Enums\ApprovalScope;
use Pandora\Approvals\Enums\ApprovalStatus;
use Pandora\Core\Actor\ActorContext;
use Pandora\Jobs\ContinueAgentRun;
use Pandora\Providers\Data\ToolCall;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Enums\RunStepType;
use Pandora\Runs\Run;
use Pandora\Tests\Fixtures\Tools\RefundOrderTool;
use Pandora\Tests\Support\MakesTools;
use Pandora\Tools\Enums\ToolExecutionStatus;
use Pandora\Tools\ToolExecution;
use Pandora\Tools\ToolGatekeeper;

/**
 * Phase 2 acceptance criteria 12 and 13 — the pause itself.
 *
 * A run waiting for a human holds NO job. That is the whole reason Pandora's
 * execution model is a durable state machine: a run can wait three days,
 * survive every deploy in between, and resume correctly.
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
        ->willRespondWith('The refund is on its way.');
});

it('parks the run at waiting_for_approval instead of executing', function (): void {
    $run = $this->runToolAgent('Refund order ORD-1234.');

    expect($run->state)->toBe(RunState::WaitingForApproval)
        ->and(RefundOrderTool::$refunds)->toBe([])
        ->and($run->output)->toBeNull();
});

it('leaves no job in flight while it waits', function (): void {
    $run = $this->runToolAgent('Refund order ORD-1234.');

    // Nothing owns the run and nothing is scheduled to: the ownership lease is
    // surrendered and the state is one the state machine defines as holding no
    // worker. A run in this condition survives a deploy by doing nothing.
    expect($run->state->isWaiting())->toBeTrue()
        ->and($run->owner_token)->toBeNull()
        ->and($run->owner_expires_at)->toBeNull();

    // And a continuation that fires anyway genuinely has nothing to do: the
    // state is not continuable, so the tool stays unexecuted.
    ContinueAgentRun::dispatchSync((string) $run->getKey());

    expect(RefundOrderTool::$refunds)->toBe([])
        ->and($run->refresh()->state)->toBe(RunState::WaitingForApproval);
});

it('records a pending approval carrying a human summary of the call', function (): void {
    $run = $this->runToolAgent('Refund order ORD-1234.');

    /** @var Approval $approval */
    $approval = Approval::query()->where('run_id', $run->getKey())->firstOrFail();

    expect($approval->status)->toBe(ApprovalStatus::Pending)
        ->and($approval->tool_name)->toBe('refund_order')
        // "Refund 42.00 to order ORD-1234", not "refund_order".
        ->and($approval->summary)->toContain('42.00')
        ->and($approval->summary)->toContain('ORD-1234')
        ->and($approval->sanitized_arguments)->toBe([
            'reference' => 'ORD-1234',
            'amount_minor' => 4200,
        ])
        ->and($approval->expires_at->isFuture())->toBeTrue();
});

it('parks the execution alongside it, not merely the run', function (): void {
    $run = $this->runToolAgent('Refund order ORD-1234.');

    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()->where('run_id', $run->getKey())->firstOrFail();

    expect($execution->status)->toBe(ToolExecutionStatus::AwaitingApproval)
        ->and($execution->required_approval)->toBeTrue()
        ->and($execution->approval_id)->not->toBeNull()
        // The real arguments survive the pause; they have to, to be executed.
        ->and($execution->arguments)->toBe(['reference' => 'ORD-1234', 'amount_minor' => 4200]);
});

it('traces the approval request where an operator will find it', function (): void {
    $run = $this->runToolAgent('Refund order ORD-1234.');

    $step = $run->steps()->where('type', RunStepType::ApprovalRequest->value)->firstOrFail();

    expect($step->payload['tool'])->toBe('refund_order')
        ->and($step->payload['risk'])->toBe('high')
        ->and($step->label)->toContain('ORD-1234');
});

it('survives a worker restart and still resumes on approval', function (): void {
    $run = $this->runToolAgent('Refund order ORD-1234.');

    /** @var Approval $approval */
    $approval = Approval::query()->where('run_id', $run->getKey())->firstOrFail();

    // Simulate the restart: throw away every cache entry, including the run
    // lock, and rebuild the tool registry from configuration the way a fresh
    // worker process would. What survives is what is in the database, which is
    // the whole claim.
    cache()->clear();
    app()->forgetInstance(ToolGatekeeper::class);
    $this->registerTools([RefundOrderTool::class]);

    app(ApprovalManager::class)->approve(
        (string) $approval->getKey(),
        ActorContext::forUser($this->toolUser()),
        authorize: false,
    );

    /** @var Run $resumed */
    $resumed = Run::query()->findOrFail($run->getKey());

    expect($resumed->state)->toBe(RunState::Completed)
        ->and(RefundOrderTool::$refunds)->toBe([['reference' => 'ORD-1234', 'amount' => 4200]])
        ->and($resumed->output)->toBe('The refund is on its way.');
});

it('does not pause a second time for a run-scoped approval already given', function (): void {
    $run = $this->runToolAgent('Refund order ORD-1234.');

    /** @var Approval $approval */
    $approval = Approval::query()->where('run_id', $run->getKey())->firstOrFail();

    // Re-script: the first run consumed only the tool-call response, and the
    // resumed run must find the SECOND call next, not the final answer.
    $this->fakeProvider()
        ->reset()
        ->willRequestTools([new ToolCall('call_2', 'refund_order', [
            'reference' => 'ORD-9999',
            'amount_minor' => 100,
        ])])
        ->willRespondWith('Both refunds are done.');

    app(ApprovalManager::class)->approve(
        (string) $approval->getKey(),
        ActorContext::forUser($this->toolUser()),
        ApprovalScope::Run,
        authorize: false,
    );

    /** @var Run $resumed */
    $resumed = Run::query()->findOrFail($run->getKey());

    expect($resumed->state)->toBe(RunState::Completed)
        ->and(RefundOrderTool::$refunds)->toHaveCount(2)
        // One approval covered both calls; a second was never requested.
        ->and(Approval::query()->where('run_id', $run->getKey())->count())->toBe(1);
});
