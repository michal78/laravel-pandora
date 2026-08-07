<?php

declare(strict_types=1);

use Pandora\Agents\AgentRunner;
use Pandora\Runs\Enums\RunState;
use Pandora\Tests\Support\MakesRuns;

uses(MakesRuns::class);

/**
 * `run()` must mean "execute and wait" on any queue connection.
 *
 * Found in a real host application, not here: the suite's default connection is
 * `sync`, which silently made every chained continuation synchronous too. On a
 * database or Redis queue the caller returned while the run was still starting,
 * and nothing finished until a worker happened to pick the continuation up.
 */
beforeEach(function (): void {
    // Anything other than `sync`, so a leaked continuation lands in a queue
    // instead of executing inline.
    config()->set('queue.default', 'database');
});

it('completes a run in-process even when the queue connection is not sync', function (): void {
    $this->fakeProvider()->willRespondWith('Finished without a worker.');

    $run = app(AgentRunner::class)
        ->agent($this->makeAgent())
        ->inConversation($this->makeConversation())
        ->run('Are you done?');

    expect($run->state)->toBe(RunState::Completed)
        ->and($run->output)->toBe('Finished without a worker.')
        ->and($run->finished_at)->not->toBeNull();
});

it('still queues the continuation when the run was dispatched, not awaited', function (): void {
    $this->fakeProvider()->willRespondWith('Later.');

    $run = app(AgentRunner::class)
        ->agent($this->makeAgent())
        ->inConversation($this->makeConversation())
        ->dispatch('Are you done?');

    // dispatch() hands off; the web request never waits for a run.
    expect($run->state)->toBe(RunState::Queued)
        ->and($run->finished_at)->toBeNull();
});
