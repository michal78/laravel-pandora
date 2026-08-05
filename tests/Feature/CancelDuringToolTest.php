<?php

declare(strict_types=1);

use Pandora\Pandora\Approvals\Approval;
use Pandora\Pandora\Jobs\ExecuteToolCall;
use Pandora\Pandora\Providers\Data\ToolCall;
use Pandora\Pandora\Runs\Enums\RunState;
use Pandora\Pandora\Runs\Run;
use Pandora\Pandora\Runs\RunCanceller;
use Pandora\Pandora\Tests\Fixtures\Tools\CountingTool;
use Pandora\Pandora\Tests\Fixtures\Tools\RefundOrderTool;
use Pandora\Pandora\Tests\Support\MakesTools;
use Pandora\Pandora\Tools\Enums\ToolExecutionStatus;
use Pandora\Pandora\Tools\ToolExecution;

/**
 * Phase 2 acceptance criterion 35 — cancelling a run with tools outstanding.
 *
 * A tool killed halfway through a write is worse than a tool allowed to
 * finish, so in-flight work completes and is recorded; what has not started
 * does not start.
 */
uses(MakesTools::class);

beforeEach(function (): void {
    CountingTool::$calls = 0;
    RefundOrderTool::$refunds = [];
    $this->registerTools([CountingTool::class, RefundOrderTool::class]);
    $this->agentAllows(['counting_tool', 'refund_order']);
});

it('records the result of a call that had already finished', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'counting_tool', ['label' => 'a'])])
        ->willRespondWith('Counted.');

    $run = $this->runToolAgent('Count it.');

    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()->where('run_id', $run->getKey())->firstOrFail();

    expect($execution->status)->toBe(ToolExecutionStatus::Succeeded)
        ->and($execution->result)->not->toBeNull();
});

it('does not start a call whose run has already ended', function (): void {
    $run = $this->makeRun([
        'agent_id' => $this->agent()->getKey(),
        'state' => RunState::Cancelled->value,
    ]);

    $execution = $this->makeExecution($run, 'call_1', ToolExecutionStatus::Pending);

    ExecuteToolCall::dispatchSync((string) $execution->getKey());

    /** @var ToolExecution $after */
    $after = ToolExecution::query()->findOrFail($execution->getKey());

    expect(CountingTool::$calls)->toBe(0)
        ->and($after->status)->toBe(ToolExecutionStatus::Cancelled)
        ->and($after->decision_reason)->toContain('run ended');
});

it('leaves the cancelled run terminal rather than resuming it', function (): void {
    $run = $this->makeRun([
        'agent_id' => $this->agent()->getKey(),
        'state' => RunState::Cancelled->value,
    ]);

    $execution = $this->makeExecution($run, 'call_1', ToolExecutionStatus::Succeeded);

    $this->fakeProvider()->willRespondWith('Should not be reached.');

    ExecuteToolCall::dispatchSync((string) $execution->getKey());

    expect(Run::query()->findOrFail($run->getKey())->state)->toBe(RunState::Cancelled);
});

it('cancels a run that was waiting for an approval nobody gave', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'refund_order', [
            'reference' => 'ORD-1234', 'amount_minor' => 4200,
        ])])
        ->willRespondWith('Done.');

    $run = $this->runToolAgent('Refund order ORD-1234.');

    expect($run->state)->toBe(RunState::WaitingForApproval);

    $cancelled = app(RunCanceller::class)->cancel($run, 'Changed my mind.');

    expect($cancelled->state)->toBe(RunState::Cancelled)
        ->and(RefundOrderTool::$refunds)->toBe([])
        // The approval outlives the run as a record of what was asked; nobody
        // may now act on it, because the run it belonged to is terminal.
        ->and(Approval::query()->where('run_id', $run->getKey())->count())->toBe(1);
});
