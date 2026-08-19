<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Pandora\Audit\AuditLog;
use Pandora\Jobs\StartAgentRun;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Run;
use Pandora\Tests\Support\MakesRuns;

uses(MakesRuns::class);

/*
 * Phase 9, criterion 2 -- T2 in a queue worker.
 *
 * `ResolvesPandoraContext` re-enters the tenant a job carries, and its docblock
 * names the stake exactly: "Forgetting this is the classic way a queued job
 * silently reads across every tenant." The audit of 2026-08-19 removed the
 * re-entry and ran the suite. **All 1,818 tests passed.**
 *
 * Two things hid it. The first is `QUEUE_CONNECTION=sync` in
 * `phpunit.xml.dist`: every job runs inline, inside whatever tenant the test
 * had already entered, so the ambient tenant stands in for the carried one and
 * the carried one is never actually load-bearing. That is the same shape as the
 * serial-runner finding from T12 and T14 -- the runner is the fake -- and it is
 * why these tests dispatch from OUTSIDE any tenant, the way a worker with no
 * request and no session actually starts.
 *
 * The second is subtler and is why nothing went red even so. Losing the tenant
 * does not make a read fail, it makes it WIDER: with no tenant resolved the
 * global scope is inert, so the job finds its own run, does its work, and
 * succeeds. A test that asserts the job worked passes either way. The failure
 * mode is a leak, not an error, so it has to be asserted as one -- by the tenant
 * a job's writes land in, and by a run it must not be able to reach.
 */
it('carries its tenant into a worker that has none', function (): void {
    Queue::fake();

    $run = inTenant('acme', fn (): Run => $this->makeRun(['state' => RunState::Queued->value]));

    // No `inTenant()` here on purpose: this is the worker, and it has nothing
    // ambient to fall back on. Everything the job does must come from the
    // tenant id it was constructed with.
    $job = new StartAgentRun((string) $run->getKey(), 'acme');
    app()->call([$job, 'handle']);

    expect($run->fresh()->state)->not->toBe(RunState::Queued);

    // The witness is the audit row: `AuditLogger::record()` stamps whatever
    // tenant is current when it writes, so an unstamped row is the proof that
    // the job ran untenanted -- and an unstamped audit row is invisible to the
    // tenant whose run it describes.
    $started = AuditLog::query()->where('action', 'run.started')->get();

    expect($started)->toHaveCount(1)
        ->and($started->first()->tenant_id)->toBe('acme');
});

it('does not start a run belonging to another tenant', function (): void {
    Queue::fake();

    $globex = inTenant('globex', fn (): Run => $this->makeRun(['state' => RunState::Queued->value]));

    // A job carrying one tenant, handed another tenant's run id -- a corrupted
    // payload, a replayed message, an id that leaked through a log. The carried
    // tenant is the only thing that refuses it: `handle()` looks the run up with
    // `Run::query()->find()` and returns silently when the scope hides it.
    $job = new StartAgentRun((string) $globex->getKey(), 'acme');
    app()->call([$job, 'handle']);

    expect($globex->fresh()->state)->toBe(RunState::Queued)
        ->and(AuditLog::query()->where('action', 'run.started')->count())->toBe(0);
});
