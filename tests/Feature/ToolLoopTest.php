<?php

declare(strict_types=1);

use Pandora\Exceptions\BudgetExceeded;
use Pandora\Messages\Enums\MessageRole;
use Pandora\Messages\Message;
use Pandora\Providers\Data\ToolCall;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Enums\RunStepType;
use Pandora\Tests\Fixtures\Tools\LookupOrderTool;
use Pandora\Tests\Support\MakesTools;
use Pandora\Tools\Enums\ToolExecutionStatus;
use Pandora\Tools\ToolExecution;

/**
 * Phase 2 acceptance criteria 25 and 26 — the whole loop, end to end.
 *
 * The model asks for a tool, the tool runs, the result comes back as a
 * message, the model sees it and answers. Everything in Phase 2 exists to make
 * this paragraph true and safe.
 */
uses(MakesTools::class);

beforeEach(function (): void {
    $this->registerTools([LookupOrderTool::class]);
    $this->agentAllows(['lookup_order']);
});

it('completes a full tool loop from request to answer', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'lookup_order', ['reference' => 'ORD-1234'])])
        ->willRespondWith('Your order ORD-1234 has shipped.');

    $run = $this->runToolAgent('Where is order ORD-1234?');

    expect($run->state)->toBe(RunState::Completed)
        ->and($run->output)->toBe('Your order ORD-1234 has shipped.')
        ->and($run->iterations)->toBe(2)
        ->and($run->tool_calls_count)->toBe(1);
});

it('records the execution with its arguments, result and timing', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'lookup_order', ['reference' => 'ORD-1234'])])
        ->willRespondWith('Shipped.');

    $run = $this->runToolAgent('Where is my order?');

    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()->where('run_id', $run->getKey())->firstOrFail();

    expect($execution->status)->toBe(ToolExecutionStatus::Succeeded)
        ->and($execution->tool_name)->toBe('lookup_order')
        ->and($execution->arguments)->toBe(['reference' => 'ORD-1234'])
        ->and($execution->result['content'])->toContain('ORD-1234')
        ->and($execution->started_at)->not->toBeNull()
        ->and($execution->finished_at)->not->toBeNull()
        ->and($execution->duration_ms)->not->toBeNull();
});

it('persists the request and the result as messages the model can replay', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'lookup_order', ['reference' => 'ORD-1234'])])
        ->willRespondWith('Shipped.');

    $run = $this->runToolAgent('Where is my order?');

    /** @var Message $assistant */
    $assistant = Message::query()
        ->where('run_id', $run->getKey())
        ->where('role', MessageRole::Assistant->value)
        ->orderBy('sequence')
        ->firstOrFail();

    /** @var Message $toolMessage */
    $toolMessage = Message::query()
        ->where('run_id', $run->getKey())
        ->where('role', MessageRole::Tool->value)
        ->firstOrFail();

    expect($assistant->requestsTools())->toBeTrue()
        ->and($assistant->toolCalls()[0]->id)->toBe('call_1')
        ->and($toolMessage->tool_call_id)->toBe('call_1')
        ->and($toolMessage->content)->toContain('ORD-1234');
});

it('shows the model the tool result on its next turn', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'lookup_order', ['reference' => 'ORD-1234'])])
        ->willRespondWith('Shipped.');

    $this->runToolAgent('Where is my order?');

    $second = $this->fakeProvider()->receivedRequests()[1];
    $roles = array_map(static fn ($m): string => $m->role->value, $second->messages);

    expect($roles)->toContain('tool')
        ->and($second->messages[count($second->messages) - 1]->toolCallId)->toBe('call_1');
});

it('advertises the agent tools to the provider, and only those', function (): void {
    $this->fakeProvider()->willRespondWith('Hello.');

    $this->runToolAgent('Hello');

    $advertised = array_map(
        static fn ($t): string => $t->name,
        $this->fakeProvider()->receivedRequests()[0]->tools,
    );

    expect($advertised)->toBe(['lookup_order']);
});

it('advertises nothing to an agent with no allowlist', function (): void {
    $this->agentAllows([]);
    $this->fakeProvider()->willRespondWith('Hello.');

    $this->runToolAgent('Hello');

    expect($this->fakeProvider()->receivedRequests()[0]->tools)->toBe([]);
});

it('traces the request, the result and their order', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'lookup_order', ['reference' => 'ORD-1234'])])
        ->willRespondWith('Shipped.');

    $run = $this->runToolAgent('Where is my order?');

    $types = $run->steps()->orderBy('sequence')->get()
        ->map(static fn ($step): string => $step->type->value)
        ->all();

    expect($types)->toContain(RunStepType::ToolRequest->value)
        ->and($types)->toContain(RunStepType::ToolResult->value)
        ->and(array_search(RunStepType::ToolRequest->value, $types, true))
        ->toBeLessThan(array_search(RunStepType::ToolResult->value, $types, true));
});

it('runs several tools from one turn and continues exactly once', function (): void {
    $this->fakeProvider()
        ->willRequestTools([
            new ToolCall('call_1', 'lookup_order', ['reference' => 'ORD-1']),
            new ToolCall('call_2', 'lookup_order', ['reference' => 'ORD-2']),
            new ToolCall('call_3', 'lookup_order', ['reference' => 'ORD-3']),
        ])
        ->willRespondWith('All three have shipped.');

    $run = $this->runToolAgent('Check my three orders.');

    expect(ToolExecution::query()->where('run_id', $run->getKey())->count())->toBe(3)
        ->and($run->state)->toBe(RunState::Completed)
        // Two model turns, not four: exactly one continuation was dispatched
        // after the last tool finished.
        ->and($run->iterations)->toBe(2)
        ->and($run->output)->toBe('All three have shipped.');
});

it('lets the model answer even when every call it made was refused', function (): void {
    $this->agentAllows([]);
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'lookup_order', ['reference' => 'ORD-1'])])
        ->willRespondWith('I am not able to look that up.');

    $run = $this->runToolAgent('Where is my order?');

    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()->where('run_id', $run->getKey())->firstOrFail();

    expect($execution->status)->toBe(ToolExecutionStatus::Denied)
        ->and($run->state)->toBe(RunState::Completed)
        ->and($run->output)->toBe('I am not able to look that up.');
});

it('stops at the tool-call budget rather than looping forever', function (): void {
    $this->agent()->forceFill(['max_tool_calls' => 2, 'max_iterations' => 10])->save();

    $provider = $this->fakeProvider();

    for ($i = 0; $i < 6; $i++) {
        $provider->willRequestTools([
            new ToolCall("call_{$i}", 'lookup_order', ['reference' => "ORD-{$i}"]),
        ]);
    }

    $run = $this->runToolAgent('Check everything.');

    expect($run->state)->toBe(RunState::TimedOut)
        ->and($run->error_class)->toBe(BudgetExceeded::class);
});
