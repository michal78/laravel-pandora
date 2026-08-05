<?php

declare(strict_types=1);

use Pandora\Pandora\Jobs\ContinueAgentRun;
use Pandora\Pandora\Runs\Enums\RunState;
use Pandora\Pandora\Runs\RunCanceller;
use Pandora\Pandora\Runs\RunStateMachine;
use Pandora\Pandora\Tests\Support\MakesRuns;

uses(MakesRuns::class);

/** Acceptance criterion 13 -- cancellation. */
it('cancels a queued run immediately, since no job is executing', function (): void {
    $run = $this->makeRun();
    app(RunStateMachine::class)->transition($run, RunState::Queued);

    $run = app(RunCanceller::class)->cancel($run, 'User pressed stop.');

    expect($run->state)->toBe(RunState::Cancelled)
        ->and($run->cancel_requested_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull();
});

it('drains a running run through cancelling rather than killing it mid-write', function (): void {
    $run = $this->makeRun();
    $states = app(RunStateMachine::class);

    $states->transition($run, RunState::Queued);
    $states->transition($run, RunState::Starting);
    $states->transition($run, RunState::Running);

    $run = app(RunCanceller::class)->cancel($run);

    expect($run->state)->toBe(RunState::Cancelling);
});

it('honours a cancellation request at the next continuation', function (): void {
    $this->fakeProvider()->willRespondWith('should never be produced');

    $run = $this->makeRun(['conversation_id' => $this->makeConversation()->getKey()]);
    $states = app(RunStateMachine::class);

    $states->transition($run, RunState::Queued);
    $states->transition($run, RunState::Starting);
    $states->transition($run, RunState::Running);

    $run->forceFill(['cancel_requested_at' => now()])->save();

    dispatch_sync(new ContinueAgentRun((string) $run->getKey()));

    expect($run->refresh()->state)->toBe(RunState::Cancelled)
        // The run stopped before calling the provider.
        ->and($this->fakeProvider()->receivedRequests())->toBeEmpty();
});

it('cancels child runs along with their parent', function (): void {
    $parent = $this->makeRun();
    $states = app(RunStateMachine::class);
    $states->transition($parent, RunState::Queued);
    $states->transition($parent, RunState::Starting);
    $states->transition($parent, RunState::Running);

    $child = $this->makeRun([
        'parent_run_id' => $parent->getKey(),
        'delegation_depth' => 1,
    ]);
    $states->transition($child, RunState::Queued);

    app(RunCanceller::class)->cancel($parent);

    expect($child->refresh()->state)->toBe(RunState::Cancelled);
});

it('leaves an already-terminal run alone', function (): void {
    $run = $this->makeRun();
    $states = app(RunStateMachine::class);
    $states->transition($run, RunState::Queued);
    $states->transition($run, RunState::Starting);
    $states->transition($run, RunState::Running);
    $states->transition($run, RunState::Completed);

    $run = app(RunCanceller::class)->cancel($run);

    expect($run->state)->toBe(RunState::Completed);
});
