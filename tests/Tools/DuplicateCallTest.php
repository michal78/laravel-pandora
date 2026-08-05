<?php

declare(strict_types=1);

use Pandora\Pandora\Messages\Enums\MessageRole;
use Pandora\Pandora\Messages\Message;
use Pandora\Pandora\Providers\Data\ToolCall;
use Pandora\Pandora\Runs\Enums\RunState;
use Pandora\Pandora\Tests\Fixtures\Tools\LookupOrderTool;
use Pandora\Pandora\Tests\Support\MakesTools;
use Pandora\Pandora\Tools\Enums\ToolExecutionStatus;
use Pandora\Pandora\Tools\ToolExecution;

/**
 * Phase 2 acceptance criterion 21 — the loop where a model re-asks the same
 * question because it did not like the answer.
 *
 * Cheap to fall into and expensive to fall into repeatedly, so the guard
 * refuses and tells the model exactly what happened.
 */
uses(MakesTools::class);

beforeEach(function (): void {
    $this->registerTools([LookupOrderTool::class]);
    $this->agentAllows(['lookup_order']);
});

it('denies a second identical call in the same run', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'lookup_order', ['reference' => 'ORD-1234'])])
        ->willRequestTools([new ToolCall('call_2', 'lookup_order', ['reference' => 'ORD-1234'])])
        ->willRespondWith('It shipped.');

    $run = $this->runToolAgent('Where is ORD-1234?');

    $statuses = ToolExecution::query()
        ->where('run_id', $run->getKey())
        ->orderBy('created_at')
        ->get()
        ->map(static fn (ToolExecution $e): string => $e->status->value)
        ->all();

    expect($statuses)->toBe(['succeeded', 'denied'])
        ->and($run->state)->toBe(RunState::Completed);
});

it('tells the model to use the answer it already has', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'lookup_order', ['reference' => 'ORD-1234'])])
        ->willRequestTools([new ToolCall('call_2', 'lookup_order', ['reference' => 'ORD-1234'])])
        ->willRespondWith('It shipped.');

    $run = $this->runToolAgent('Where is ORD-1234?');

    /** @var ToolExecution $denied */
    $denied = ToolExecution::query()
        ->where('run_id', $run->getKey())
        ->where('status', ToolExecutionStatus::Denied->value)
        ->firstOrFail();

    expect($denied->decision_reason)->toContain('already made in this run')
        ->and($denied->decided_by)->toBe('budget');
});

it('recognises the same call however the model ordered the arguments', function (): void {
    // Canonicalised, so `{a, b}` and `{b, a}` are one call, which is the whole
    // point of the guard.
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'lookup_order', [
            'reference' => 'ORD-1234', 'include_lines' => true,
        ])])
        ->willRequestTools([new ToolCall('call_2', 'lookup_order', [
            'include_lines' => true, 'reference' => 'ORD-1234',
        ])])
        ->willRespondWith('It shipped.');

    $run = $this->runToolAgent('Where is ORD-1234?');

    expect(ToolExecution::query()
        ->where('run_id', $run->getKey())
        ->where('status', ToolExecutionStatus::Denied->value)
        ->count())->toBe(1);
});

it('permits the same tool with different arguments', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'lookup_order', ['reference' => 'ORD-1'])])
        ->willRequestTools([new ToolCall('call_2', 'lookup_order', ['reference' => 'ORD-2'])])
        ->willRespondWith('Both shipped.');

    $run = $this->runToolAgent('Where are my orders?');

    expect(ToolExecution::query()
        ->where('run_id', $run->getKey())
        ->where('status', ToolExecutionStatus::Succeeded->value)
        ->count())->toBe(2);
});

it('confines the guard to one run', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'lookup_order', ['reference' => 'ORD-1234'])])
        ->willRespondWith('It shipped.')
        ->willRequestTools([new ToolCall('call_1', 'lookup_order', ['reference' => 'ORD-1234'])])
        ->willRespondWith('Still shipped.');

    $this->runToolAgent('Where is ORD-1234?');
    $second = $this->runToolAgent('And now?');

    expect(ToolExecution::query()
        ->where('run_id', $second->getKey())
        ->where('status', ToolExecutionStatus::Succeeded->value)
        ->count())->toBe(1);
});

it('can be switched off by a deployment that wants no guard', function (): void {
    config()->set('pandora.tools.duplicate_threshold', 0);

    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'lookup_order', ['reference' => 'ORD-1234'])])
        ->willRequestTools([new ToolCall('call_2', 'lookup_order', ['reference' => 'ORD-1234'])])
        ->willRespondWith('It shipped.');

    $run = $this->runToolAgent('Where is ORD-1234?');

    expect(ToolExecution::query()
        ->where('run_id', $run->getKey())
        ->where('status', ToolExecutionStatus::Succeeded->value)
        ->count())->toBe(2);
});

it('still answers the model rather than stalling the run', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'lookup_order', ['reference' => 'ORD-1234'])])
        ->willRequestTools([new ToolCall('call_2', 'lookup_order', ['reference' => 'ORD-1234'])])
        ->willRespondWith('It shipped.');

    $run = $this->runToolAgent('Where is ORD-1234?');

    // A denied call still produces a tool result message, or the next request
    // would carry a tool call nobody answered.
    expect(Message::query()
        ->where('run_id', $run->getKey())
        ->where('role', MessageRole::Tool->value)
        ->count())->toBe(2);
});
