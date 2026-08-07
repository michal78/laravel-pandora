<?php

declare(strict_types=1);

use Pandora\Jobs\ExecuteToolCall;
use Pandora\Providers\Data\ToolCall;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Run;
use Pandora\Tests\Fixtures\Tools\CountingTool;
use Pandora\Tests\Support\MakesTools;
use Pandora\Tools\Enums\ToolExecutionStatus;
use Pandora\Tools\ToolExecution;

/**
 * Phase 2 acceptance criteria 22 and 23 — what an at-least-once queue does to
 * a side effect, and how N parallel calls become exactly one continuation.
 */
uses(MakesTools::class);

beforeEach(function (): void {
    CountingTool::$calls = 0;
    $this->registerTools([CountingTool::class]);
    $this->agentAllows(['counting_tool']);
});

it('applies a side effect once however many times its job is delivered', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'counting_tool', ['label' => 'once'])])
        ->willRespondWith('Counted.');

    $run = $this->runToolAgent('Count it.');

    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()->where('run_id', $run->getKey())->firstOrFail();

    ExecuteToolCall::dispatchSync((string) $execution->getKey());
    ExecuteToolCall::dispatchSync((string) $execution->getKey());
    ExecuteToolCall::dispatchSync((string) $execution->getKey());

    expect(CountingTool::$calls)->toBe(1);
});

it('derives an idempotency key from the run, tool and canonicalised arguments', function (): void {
    $a = ToolExecution::idempotencyKey('run-1', 'refund', ['b' => 2, 'a' => 1]);
    $b = ToolExecution::idempotencyKey('run-1', 'refund', ['a' => 1, 'b' => 2]);
    $c = ToolExecution::idempotencyKey('run-2', 'refund', ['a' => 1, 'b' => 2]);
    $d = ToolExecution::idempotencyKey('run-1', 'refund', ['a' => 1, 'b' => 3]);
    $e = ToolExecution::idempotencyKey('run-1', 'refund', ['a' => 1, 'b' => 2], attempt: 2);

    expect($a)->toBe($b)
        ->and($a)->not->toBe($c)
        ->and($a)->not->toBe($d)
        ->and($a)->not->toBe($e);
});

it('canonicalises nested arguments too', function (): void {
    expect(ToolExecution::idempotencyKey('r', 't', ['x' => ['b' => 1, 'a' => 2]]))
        ->toBe(ToolExecution::idempotencyKey('r', 't', ['x' => ['a' => 2, 'b' => 1]]));
});

it('dispatches exactly one continuation after several parallel calls', function (): void {
    $this->fakeProvider()
        ->willRequestTools([
            new ToolCall('call_1', 'counting_tool', ['label' => 'a']),
            new ToolCall('call_2', 'counting_tool', ['label' => 'b']),
            new ToolCall('call_3', 'counting_tool', ['label' => 'c']),
            new ToolCall('call_4', 'counting_tool', ['label' => 'd']),
        ])
        ->willRespondWith('All counted.');

    $run = $this->runToolAgent('Count four things.');

    // Four tools ran; the model was asked exactly twice. Had each tool
    // dispatched its own continuation, iterations would be five.
    expect(CountingTool::$calls)->toBe(4)
        ->and($run->iterations)->toBe(2)
        ->and($run->state)->toBe(RunState::Completed);
});

it('holds the continuation back while any call is still open', function (): void {
    $run = $this->makeRun([
        'agent_id' => $this->agent()->getKey(),
        'state' => RunState::WaitingForTool->value,
    ]);

    $finished = $this->makeExecution($run, 'call_1', ToolExecutionStatus::Succeeded);
    $this->makeExecution($run, 'call_2', ToolExecutionStatus::Pending);

    $this->fakeProvider()->willRespondWith('Should not be reached.');

    // The job for the call that DID finish runs its fan-in. One call is still
    // open, so the run must not move.
    ExecuteToolCall::dispatchSync((string) $finished->getKey());

    /** @var Run $after */
    $after = Run::query()->findOrFail($run->getKey());

    expect($after->state)->toBe(RunState::WaitingForTool)
        ->and($after->iterations)->toBe(0);
});

it('releases the continuation once the last call closes', function (): void {
    $run = $this->makeRun([
        'agent_id' => $this->agent()->getKey(),
        'state' => RunState::WaitingForTool->value,
    ]);

    $finished = $this->makeExecution($run, 'call_1', ToolExecutionStatus::Succeeded);

    $this->fakeProvider()->willRespondWith('All done.');

    ExecuteToolCall::dispatchSync((string) $finished->getKey());

    /** @var Run $after */
    $after = Run::query()->findOrFail($run->getKey());

    expect($after->state)->toBe(RunState::Completed)
        ->and($after->iterations)->toBe(1)
        ->and($after->output)->toBe('All done.');
});

it('does not continue a run that is waiting for a human', function (): void {
    // Approved calls finishing does not resolve the ones nobody has decided.
    $run = $this->makeRun([
        'agent_id' => $this->agent()->getKey(),
        'state' => RunState::WaitingForApproval->value,
    ]);

    $finished = $this->makeExecution($run, 'call_1', ToolExecutionStatus::Succeeded);

    $this->fakeProvider()->willRespondWith('Should not be reached.');

    ExecuteToolCall::dispatchSync((string) $finished->getKey());

    /** @var Run $after */
    $after = Run::query()->findOrFail($run->getKey());

    expect($after->state)->toBe(RunState::WaitingForApproval)
        ->and($after->iterations)->toBe(0);
});

it('records one execution row per call, keyed by the provider call id', function (): void {
    $this->fakeProvider()
        ->willRequestTools([
            new ToolCall('call_1', 'counting_tool', ['label' => 'a']),
            new ToolCall('call_2', 'counting_tool', ['label' => 'b']),
        ])
        ->willRespondWith('Done.');

    $run = $this->runToolAgent('Count two things.');

    $ids = ToolExecution::query()
        ->where('run_id', $run->getKey())
        ->orderBy('tool_call_id')
        ->pluck('tool_call_id')
        ->all();

    expect($ids)->toBe(['call_1', 'call_2']);
});
