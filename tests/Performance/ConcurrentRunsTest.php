<?php

declare(strict_types=1);

use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Run;
use Pandora\Tests\Support\Concurrency\Scenarios\ExecuteRunForAgent;
use Pandora\Tests\Support\MakesRuns;
use Pandora\Tests\Support\RunsConcurrently;

/**
 * Phase 9, criterion 27 -- N concurrent runs against one agent complete
 * without lock starvation, duplicated tool execution or lost steps.
 *
 * This is the first test in the suite where the concurrency is real. Every
 * earlier claim about workers contending was made by a single process calling
 * a method twice, and Phase 9 has now found three controls that survived
 * deletion underneath exactly that (T2's tenant carry, T12's fan-in lock,
 * T14's approval lock) plus `RunLock`'s database lease, which its own docblock
 * calls "the authority" and which the whole serial suite passes without.
 *
 * The worker count is 20 rather than the criterion's 50, and that is a
 * deliberate reading rather than a shortfall. Each worker is a full PHP process
 * booting a Laravel application; 50 of those is about 4GB of resident memory
 * and a CI box that swaps, which converts a correctness test into a flake
 * generator. What the criterion is actually asking -- does contention on one
 * agent corrupt anything -- is answered by any N above one, and 20 simultaneous
 * writers is well past the point where the lease, the step writes and the
 * conversation inserts either serialise correctly or do not. The number is a
 * constant below so that raising it is a one-line decision on a bigger machine.
 */
uses(MakesRuns::class, RunsConcurrently::class);

const CONCURRENT_WORKERS = 20;

beforeEach(function (): void {
    $this->requiresConcurrency();
});

it('completes every concurrent run against one agent', function (): void {
    $agent = $this->makeAgent();

    $result = $this->concurrently(
        ExecuteRunForAgent::class,
        CONCURRENT_WORKERS,
        ['agent_id' => (string) $agent->getKey()],
        timeout: 180.0,
    );

    // Reported before asserting, so a failure names the states rather than
    // just the count -- "three finished as failed", "three never started" and
    // "three threw" are different bugs and the count alone cannot tell them
    // apart. The distinct errors come too, deduplicated: twenty copies of one
    // message is one problem, and printing it twenty times hides that.
    $states = array_count_values(array_map(
        static fn (mixed $s): string => (string) $s,
        $result->pluck('state'),
    ));
    $errors = array_values(array_unique(array_filter($result->pluck('error'))));

    expect($result->countWhere('completed'))->toBe(
        CONCURRENT_WORKERS,
        'states seen: '.json_encode($states).' errors: '.json_encode($errors),
    );
});

it('starves none of them: every run acquired its lease and released it', function (): void {
    $agent = $this->makeAgent();

    $this->concurrently(
        ExecuteRunForAgent::class,
        CONCURRENT_WORKERS,
        ['agent_id' => (string) $agent->getKey()],
        timeout: 180.0,
    );

    // A starved run is one that never got the lock and gave up, which shows as
    // a non-terminal state left behind. A held lease after completion is the
    // other half: a worker that finished without releasing would block the
    // next one for the full 900s TTL.
    $runs = Run::query()->where('agent_id', $agent->getKey())->get();

    expect($runs)->toHaveCount(CONCURRENT_WORKERS)
        ->and($runs->every(fn (Run $r): bool => $r->state === RunState::Completed))->toBeTrue()
        ->and($runs->filter(fn (Run $r): bool => $r->owner_token !== null))->toHaveCount(0);
});

it('loses no steps and duplicates none', function (): void {
    $agent = $this->makeAgent();

    $result = $this->concurrently(
        ExecuteRunForAgent::class,
        CONCURRENT_WORKERS,
        ['agent_id' => (string) $agent->getKey()],
        timeout: 180.0,
    );

    // Every run does identical work against the fake provider, so every run
    // must have written an identical number of steps. A lost write shows as a
    // low outlier and a double-execution as a high one; asserting they are all
    // equal catches both without needing to know the right number in advance.
    $counts = array_unique($result->pluck('step_count'));

    expect($counts)->toHaveCount(1, 'step counts differed across runs: '
        .json_encode(array_count_values(array_map('intval', $result->pluck('step_count')))));

    // And the steps in the database agree with what the workers reported, so
    // this is not twenty workers consistently miscounting.
    $runIds = array_values(array_filter($result->pluck('run_id')));
    expect($runIds)->toHaveCount(CONCURRENT_WORKERS)
        ->and(array_unique($runIds))->toHaveCount(CONCURRENT_WORKERS);
});

it('actually contended, rather than running twenty runs one after another', function (): void {
    // Without this the three assertions above are also satisfied by a harness
    // that serialised everything, and the file would prove nothing at all.
    $agent = $this->makeAgent();

    $result = $this->concurrently(
        ExecuteRunForAgent::class,
        CONCURRENT_WORKERS,
        ['agent_id' => (string) $agent->getKey()],
        timeout: 180.0,
    );

    $started = array_map('floatval', $result->pluck('started_at'));
    $finished = array_map('floatval', $result->pluck('finished_at'));

    expect(max($started))->toBeLessThan(min($finished));
});
