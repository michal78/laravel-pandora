<?php

declare(strict_types=1);

use Pandora\Pandora\Jobs\ContinueAgentRun;
use Pandora\Pandora\Runs\Enums\RunState;
use Pandora\Pandora\Runs\Run;
use Pandora\Pandora\Runs\RunLock;
use Pandora\Pandora\Runs\RunStateMachine;
use Pandora\Pandora\Tests\Support\MakesRuns;

uses(MakesRuns::class);

/** Acceptance guarantee 19 -- worker crash recovery and lock discipline. */
function runningRun(object $test): Run
{
    $run = $test->makeRun(['conversation_id' => $test->makeConversation()->getKey()]);
    $states = app(RunStateMachine::class);

    $states->transition($run, RunState::Queued);
    $states->transition($run, RunState::Starting);
    $states->transition($run, RunState::Running);

    return $run->refresh();
}

it('grants ownership to one worker and refuses a second', function (): void {
    $run = runningRun($this);
    $locks = app(RunLock::class);

    $first = $locks->acquire((string) $run->getKey());
    $second = $locks->acquire((string) $run->getKey());

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and($locks->isHeldBy((string) $run->getKey(), $first))->toBeTrue();
});

it('lets another worker take over once the lease expires', function (): void {
    $run = runningRun($this);
    $locks = app(RunLock::class);

    $first = $locks->acquire((string) $run->getKey());
    expect($first)->not->toBeNull();

    // Simulate a killed worker: the lease expires with nobody releasing it.
    $run->forceFill(['owner_expires_at' => now()->subMinute()])->save();
    cache()->flush();

    expect($locks->acquire((string) $run->getKey()))->not->toBeNull();
});

it('detects a run stalled behind an expired lease', function (): void {
    $run = runningRun($this);
    $locks = app(RunLock::class);

    $locks->acquire((string) $run->getKey());
    expect($locks->stalledRuns())->toHaveCount(0);

    $run->forceFill(['owner_expires_at' => now()->subMinutes(30)])->save();

    expect($locks->stalledRuns()->pluck('id')->all())->toContain($run->getKey());
});

it('does nothing when a continuation finds the run already owned', function (): void {
    $this->fakeProvider()->willRespondWith('should not be produced');

    $run = runningRun($this);

    // Another worker holds it.
    app(RunLock::class)->acquire((string) $run->getKey());

    dispatch_sync(new ContinueAgentRun((string) $run->getKey()));

    expect($run->refresh()->state)->toBe(RunState::Running)
        ->and($this->fakeProvider()->receivedRequests())->toBeEmpty();
});

it('releases ownership after a successful iteration', function (): void {
    $this->fakeProvider()->willRespondWith('All done.');

    $run = runningRun($this);

    dispatch_sync(new ContinueAgentRun((string) $run->getKey()));

    $run->refresh();

    expect($run->state)->toBe(RunState::Completed)
        ->and($run->owner_token)->toBeNull()
        ->and($run->owner_expires_at)->toBeNull();
});

it('completes a retried iteration without duplicating trace steps', function (): void {
    $this->fakeProvider()->willRespondWith('First attempt.');

    $run = runningRun($this);

    dispatch_sync(new ContinueAgentRun((string) $run->getKey()));
    $stepsAfterFirst = $run->refresh()->steps()->count();

    // A duplicate delivery of the same job after the run already finished.
    dispatch_sync(new ContinueAgentRun((string) $run->getKey()));

    expect($run->refresh()->steps()->count())->toBe($stepsAfterFirst)
        ->and($run->state)->toBe(RunState::Completed);
});

it('renews a lease during a long iteration', function (): void {
    $run = runningRun($this);
    $locks = app(RunLock::class);

    $token = $locks->acquire((string) $run->getKey());
    $originalExpiry = $run->refresh()->owner_expires_at;

    $this->travel(30)->seconds();

    expect($locks->renew((string) $run->getKey(), $token))->toBeTrue()
        ->and($run->refresh()->owner_expires_at->greaterThan($originalExpiry))->toBeTrue();
});
