<?php

declare(strict_types=1);

use Pandora\Audit\AuditLog;
use Pandora\Exceptions\ImmutableRecord;
use Pandora\Runs\Enums\RunStepStatus;
use Pandora\Runs\Enums\RunStepType;
use Pandora\Runs\RunStep;
use Pandora\Runs\RunStepRecorder;
use Pandora\Tests\Support\MakesRuns;

uses(MakesRuns::class);

/**
 * Acceptance guarantee 20 -- append-only records.
 *
 * A trace that can be quietly rewritten is not a trace, so immutability is
 * enforced by the model rather than left to convention.
 */
it('refuses to update a run step', function (): void {
    $step = app(RunStepRecorder::class)->record(
        $this->makeRun(), RunStepType::ModelRequest, RunStepStatus::Succeeded,
    );

    $step->label = 'tampered';
    $step->save();
})->throws(ImmutableRecord::class);

it('refuses to delete a run step outside the retention pruner', function (): void {
    $step = app(RunStepRecorder::class)->record(
        $this->makeRun(), RunStepType::ModelRequest,
    );

    $step->delete();
})->throws(ImmutableRecord::class);

it('permits deletion only through the pruning path', function (): void {
    $step = app(RunStepRecorder::class)->record(
        $this->makeRun(), RunStepType::ModelRequest,
    );

    $step->markForPruning()->delete();

    expect(RunStep::query()->find($step->getKey()))->toBeNull();
});

it('refuses to update an audit log', function (): void {
    $log = AuditLog::query()->create(['action' => 'run.started', 'severity' => 'info']);

    $log->action = 'run.tampered';
    $log->save();
})->throws(ImmutableRecord::class);

it('allocates contiguous step sequences', function (): void {
    $run = $this->makeRun();
    $recorder = app(RunStepRecorder::class);

    foreach (range(1, 5) as $ignored) {
        $recorder->record($run, RunStepType::ModelRequest);
    }

    expect(RunStep::query()->where('run_id', $run->getKey())->orderBy('sequence')->pluck('sequence')->all())
        ->toBe([1, 2, 3, 4, 5]);
});
