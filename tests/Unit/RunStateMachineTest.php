<?php

declare(strict_types=1);

use Pandora\Exceptions\InvalidRunTransition;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\RunStateMachine;
use Pandora\Tests\Support\MakesRuns;

uses(MakesRuns::class);

/** Acceptance criterion 9 -- state transitions. */
it('allows the happy path through to completion', function (): void {
    $machine = app(RunStateMachine::class);
    $run = $this->makeRun();

    expect($run->state)->toBe(RunState::Pending);

    $run = $machine->transition($run, RunState::Queued);
    expect($run->state)->toBe(RunState::Queued)
        ->and($run->queued_at)->not->toBeNull();

    $run = $machine->transition($run, RunState::Starting);
    expect($run->state)->toBe(RunState::Starting)
        ->and($run->started_at)->not->toBeNull();

    $run = $machine->transition($run, RunState::Running);
    $run = $machine->transition($run, RunState::Completed);

    expect($run->state)->toBe(RunState::Completed)
        ->and($run->finished_at)->not->toBeNull()
        // Ownership is released on any terminal state.
        ->and($run->owner_token)->toBeNull();
});

it('rejects an illegal transition rather than silently ignoring it', function (): void {
    $machine = app(RunStateMachine::class);
    $run = $this->makeRun();

    // pending -> running skips queued/starting.
    $machine->transition($run, RunState::Running);
})->throws(InvalidRunTransition::class);

it('refuses to transition out of a terminal state', function (): void {
    $machine = app(RunStateMachine::class);
    $run = $this->makeRun();

    $machine->transition($run, RunState::Queued);
    $machine->transition($run, RunState::Cancelled);

    $machine->transition($run, RunState::Running);
})->throws(InvalidRunTransition::class);

it('classifies terminal, waiting and continuable states correctly', function (): void {
    expect(RunState::Completed->isTerminal())->toBeTrue()
        ->and(RunState::Failed->isTerminal())->toBeTrue()
        ->and(RunState::Cancelled->isTerminal())->toBeTrue()
        ->and(RunState::TimedOut->isTerminal())->toBeTrue()
        ->and(RunState::Running->isTerminal())->toBeFalse();

    // The states that hold no worker -- the reason for the architecture.
    expect(RunState::WaitingForApproval->isWaiting())->toBeTrue()
        ->and(RunState::WaitingForUser->isWaiting())->toBeTrue()
        ->and(RunState::Paused->isWaiting())->toBeTrue()
        ->and(RunState::Running->isWaiting())->toBeFalse();

    expect(RunState::Running->isContinuable())->toBeTrue()
        ->and(RunState::Starting->isContinuable())->toBeTrue()
        ->and(RunState::Completed->isContinuable())->toBeFalse();
});

it('permits every waiting state to resume to running', function (): void {
    $machine = app(RunStateMachine::class);

    foreach ([RunState::WaitingForApproval, RunState::WaitingForUser, RunState::Paused] as $waiting) {
        expect($machine->canTransition($waiting, RunState::Running))->toBeTrue();
    }
});
