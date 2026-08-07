<?php

declare(strict_types=1);

use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Enums\TriggerType;
use Pandora\Runs\Run;
use Pandora\Tests\Support\MakesDelegations;
use Pandora\Tools\ToolExecution;

/**
 * Phase 6 acceptance criterion 1 — a child run is a first-class run that knows
 * where it came from.
 *
 * Everything else in this phase reads these columns. If a child run cannot say
 * who its parent is and how deep it sits, then the depth limit has nothing to
 * count, the budget has no tree to sum, cancellation has nothing to walk, and
 * the trace cannot explain any of it after the fact.
 */
uses(MakesDelegations::class);

beforeEach(function (): void {
    $this->registerDelegationTools();
});

it('records the parent, the depth and the delegation trigger on the child', function (): void {
    [$parent] = $this->makeDelegationPair();

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('The specialist says it is fine.')
        ->willRespondWith('All done.');

    $parentRun = $this->runParent();
    $child = $this->childOf($parentRun);

    expect($child)->not->toBeNull()
        ->and($child->parent_run_id)->toBe((string) $parentRun->getKey())
        ->and($child->delegation_depth)->toBe(1)
        ->and($child->trigger_type)->toBe(TriggerType::Delegation)
        ->and($child->agent_id)->not->toBe($parentRun->agent_id);
});

it('starts the root run at depth zero', function (): void {
    $this->makeDelegationPair();

    $this->fakeProvider()->willRespondWith('Nothing to delegate.');

    expect($this->runParent()->delegation_depth)->toBe(0);
});

it('gives the child its own run row rather than reusing the parent', function (): void {
    $this->makeDelegationPair();

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('Specialist answer.')
        ->willRespondWith('Done.');

    $parentRun = $this->runParent();

    expect(Run::query()->count())->toBe(2)
        ->and($this->childOf($parentRun)->getKey())->not->toBe($parentRun->getKey());
});

/**
 * The child shares the parent's correlation id and NOT its conversation.
 *
 * Two halves of one decision. A shared correlation id is what lets an operator
 * pull the whole tree out of the audit log as one piece of work. A shared
 * conversation would put the child's working notes in front of the user and,
 * worse, would feed the child's raw output back into the parent's context by a
 * route that skips the tool result -- which is the one place it is treated as
 * untrusted.
 */
it('shares the correlation id but not the conversation', function (): void {
    $this->makeDelegationPair();

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('Specialist answer.')
        ->willRespondWith('Done.');

    $parentRun = $this->runParent();
    $child = $this->childOf($parentRun);

    expect($child->correlation_id)->toBe($parentRun->correlation_id)
        ->and($parentRun->conversation_id)->not->toBeNull()
        ->and($child->conversation_id)->toBeNull();
});

it('links the child to the parent tool call it answers', function (): void {
    $this->makeDelegationPair();

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('Specialist answer.')
        ->willRespondWith('Done.');

    $parentRun = $this->runParent();
    $child = $this->childOf($parentRun);

    expect($child->delegated_tool_execution_id)->not->toBeNull();

    $execution = ToolExecution::query()
        ->findOrFail($child->delegated_tool_execution_id);

    expect($execution->run_id)->toBe((string) $parentRun->getKey())
        ->and($execution->tool_name)->toBe('delegate_to_agent');
});

it('runs the child to completion and lets the parent finish', function (): void {
    $this->makeDelegationPair();

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('The order shipped on Tuesday.')
        ->willRespondWith('Your order shipped on Tuesday.');

    $parentRun = $this->runParent();

    expect($parentRun->state)->toBe(RunState::Completed)
        ->and($this->childOf($parentRun)->state)->toBe(RunState::Completed);
});

it('navigates from a child to its tree root and back down', function (): void {
    $this->makeDelegationPair();

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('Specialist answer.')
        ->willRespondWith('Done.');

    $parentRun = $this->runParent();
    $child = $this->childOf($parentRun);

    expect($child->treeRoot()->getKey())->toBe($parentRun->getKey())
        ->and($parentRun->treeRoot()->getKey())->toBe($parentRun->getKey())
        ->and($child->treeRunIds())->toHaveCount(2)
        ->and($child->treeRunIds())->toContain((string) $child->getKey())
        ->and($child->isDelegated())->toBeTrue()
        ->and($parentRun->isDelegated())->toBeFalse();
});
