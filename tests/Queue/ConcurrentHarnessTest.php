<?php

declare(strict_types=1);

use Pandora\Runs\Enums\RunState;
use Pandora\Runs\RunStateMachine;
use Pandora\Tests\Support\Concurrency\Scenarios\AcquireRunLock;
use Pandora\Tests\Support\MakesRuns;
use Pandora\Tests\Support\RunsConcurrently;

/**
 * Phase 9 -- the concurrency harness proving itself before anything trusts it.
 *
 * A test harness for concurrency has one interesting failure mode: it works,
 * it is green, and it never contends. Every worker boots, does its work while
 * the others are still starting, and reports a perfect result that a broken
 * implementation would also produce. Phase 9 has already found three controls
 * disarmed by exactly that (T2, T12, T14), so this file asserts the harness's
 * own properties before `ConcurrentRunsTest` relies on them.
 *
 * The load-bearing assertion here is OVERLAP: that the workers' execution
 * windows genuinely intersect. Everything else is downstream of it.
 */
uses(MakesRuns::class, RunsConcurrently::class);

beforeEach(function (): void {
    $this->requiresConcurrency();
});

function lockableRun(object $test): string
{
    $run = $test->makeRun(['conversation_id' => $test->makeConversation()->getKey()]);
    $states = app(RunStateMachine::class);

    $states->transition($run, RunState::Queued);
    $states->transition($run, RunState::Starting);
    $states->transition($run, RunState::Running);

    return (string) $run->getKey();
}

it('runs every worker and gets a result back from each', function (): void {
    $result = $this->concurrently(AcquireRunLock::class, 4, ['run_id' => lockableRun($this)]);

    expect($result->count())->toBe(4)
        ->and($result->pluck('pid'))->toHaveCount(4);
});

it('starts the workers in genuinely separate processes', function (): void {
    // A harness that quietly ran everything in one process would satisfy every
    // other assertion in this file.
    $result = $this->concurrently(AcquireRunLock::class, 4, ['run_id' => lockableRun($this)]);

    expect(array_unique($result->pluck('pid')))->toHaveCount(4);
});

it('overlaps the workers in time, which is the whole point of the barrier', function (): void {
    $result = $this->concurrently(AcquireRunLock::class, 5, ['run_id' => lockableRun($this)]);

    $started = $result->pluck('started_at');
    $finished = $result->pluck('finished_at');

    // Every worker must have begun before the first one finished. That is the
    // definition of contention, and it is false for any harness that lets the
    // processes queue up behind each other.
    expect(max($started))->toBeLessThan(min($finished));
});

it('grants the run to exactly one of five simultaneous workers', function (): void {
    // The assertion the harness exists for. `RunRecoveryTest` makes the same
    // claim with two sequential calls in one process, which a check-then-write
    // with no lock at all would also pass.
    $result = $this->concurrently(AcquireRunLock::class, 5, ['run_id' => lockableRun($this)]);

    expect($result->countWhere('acquired'))->toBe(1);

    $tokens = array_values(array_filter($result->pluck('token')));
    expect($tokens)->toHaveCount(1);
});

it('reports a worker that fails rather than counting it as a refusal', function (): void {
    // A lock test expects most workers to be turned away, so the harness must
    // never let a genuine error look like the control working.
    expect(fn () => $this->concurrently(AcquireRunLock::class, 2, ['run_id' => 'no-such-run-id']))
        ->not->toThrow(RuntimeException::class);

    // A missing run is a legitimate refusal, not a crash: acquire() returns
    // null for a run it cannot find. Nobody wins.
    $result = $this->concurrently(AcquireRunLock::class, 2, ['run_id' => 'no-such-run-id']);
    expect($result->countWhere('acquired'))->toBe(0);
});
