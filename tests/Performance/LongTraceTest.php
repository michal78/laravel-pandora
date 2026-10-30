<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Run;
use Pandora\Runs\RunStep;
use Pandora\Tests\Support\MakesRuns;
use Pandora\Tests\Support\MakesTools;
use Pandora\UI\Livewire\RunDetail;
use Symfony\Component\Uid\Ulid;

/**
 * Phase 9, criterion 28 -- a 500-step trace renders and paginates, and the run
 * detail page issues a bounded number of queries regardless of trace length.
 *
 * The page re-renders on every poll while a run is live, and it read the whole
 * trace each time: a long tool loop made its own trace page slower on every
 * step it added. It now reads one page of steps.
 */
uses(MakesRuns::class, MakesTools::class);

const LONG_TRACE = 500;

function seedTrace(int $steps): Run
{
    $run = test()->makeRun(['state' => RunState::Completed->value]);

    $table = (new RunStep)->getTable();
    $now = now();

    foreach (array_chunk(range(1, $steps), 250) as $chunk) {
        DB::table($table)->insert(array_map(static fn (int $sequence): array => [
            'id' => (string) new Ulid,
            'run_id' => $run->getKey(),
            'sequence' => $sequence,
            'type' => 'model_request',
            'status' => 'succeeded',
            'label' => "step-label-{$sequence}-end",
            'payload' => json_encode(['n' => $sequence]),
            'started_at' => $now,
            'finished_at' => $now,
            'created_at' => $now,
        ], $chunk));
    }

    return $run;
}

/**
 * @return array{queries: int, steps: int}
 */
function measureTrace(Run $run): array
{
    $hydrated = 0;
    $counting = true;

    RunStep::retrieved(static function () use (&$hydrated, &$counting): void {
        if ($counting) {
            $hydrated++;
        }
    });

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(RunDetail::class, ['run' => (string) $run->getKey()])->assertOk();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    $counting = false;

    return ['queries' => $queries, 'steps' => $hydrated];
}

beforeEach(function (): void {
    $this->actingAsUser($this->toolUser());
});

it('renders the first page of a 500-step trace, with the total', function (): void {
    $run = seedTrace(LONG_TRACE);

    Livewire::test(RunDetail::class, ['run' => (string) $run->getKey()])
        ->assertOk()
        ->assertSee(LONG_TRACE.' steps')
        ->assertSee('step-label-1-end')
        ->assertSee('step-label-'.RunDetail::STEPS_PER_PAGE.'-end')
        ->assertDontSee('step-label-'.(RunDetail::STEPS_PER_PAGE + 1).'-end')
        ->assertSeeInOrder(['Showing 1–'.RunDetail::STEPS_PER_PAGE, 'of '.LONG_TRACE]);
});

it('pages through to the last step', function (): void {
    $run = seedTrace(LONG_TRACE);
    $lastPage = (int) ceil(LONG_TRACE / RunDetail::STEPS_PER_PAGE);

    Livewire::test(RunDetail::class, ['run' => (string) $run->getKey()])
        ->call('gotoPage', $lastPage, 'steps')
        ->assertSee('step-label-'.LONG_TRACE.'-end')
        ->assertDontSee('step-label-1-end')
        ->call('previousPage', 'steps')
        ->assertSee('step-label-'.(LONG_TRACE - RunDetail::STEPS_PER_PAGE).'-end');
});

it('hydrates one page of steps, not the trace', function (): void {
    expect(measureTrace(seedTrace(LONG_TRACE))['steps'])->toBe(RunDetail::STEPS_PER_PAGE);
});

it('issues the same number of queries for a trace ten times longer', function (): void {
    // Both longer than a page, so both render the paginator.
    $short = measureTrace(seedTrace(LONG_TRACE / 10));
    $long = measureTrace(seedTrace(LONG_TRACE));

    expect($long['queries'])->toBe($short['queries']);
});
