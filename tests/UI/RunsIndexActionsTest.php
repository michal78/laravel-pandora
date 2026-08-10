<?php

declare(strict_types=1);

use Livewire\Livewire;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Run;
use Pandora\Tests\Support\MakesRuns;
use Pandora\UI\Livewire\RunsIndex;

/**
 * The runs list as an operator's console rather than a reading surface.
 *
 * Every test here clicks the button. The Phase 8 walkthrough found an Edit
 * control that did nothing behind thirteen tests that only ever rendered the
 * page, and the Phase 6 walkthrough named cancellation as "a button, and the
 * button is the part nobody tests". This is that test.
 */
uses(MakesRuns::class);

beforeEach(function (): void {
    $this->user = $this->actingAsUser();
});

it('cancels a live run from the list', function (): void {
    $run = $this->makeRun(['state' => RunState::Running->value]);

    Livewire::test(RunsIndex::class)
        ->call('cancel', (string) $run->getKey())
        ->assertOk();

    // Cancellation is a request, not an act: the run enters `cancelling` and
    // the worker finalises it. Asserting `cancelled` here would be asserting
    // that a queue ran inside a Livewire call.
    expect(Run::query()->find($run->getKey())->state)->toBe(RunState::Cancelling);
});

it('offers no cancel control for a run that has already finished', function (): void {
    $run = $this->makeRun(['state' => RunState::Completed->value]);

    Livewire::test(RunsIndex::class)
        ->assertOk()
        ->assertDontSeeHtml("cancel('{$run->getKey()}')");
});

it('leaves a finished run alone when the click arrives late', function (): void {
    // The list polls, so a row can finish between the render and the click.
    $run = $this->makeRun(['state' => RunState::Completed->value]);

    Livewire::test(RunsIndex::class)
        ->call('cancel', (string) $run->getKey())
        ->assertOk();

    expect(Run::query()->find($run->getKey())->state)->toBe(RunState::Completed);
});

it('names the agent on every row', function (): void {
    $agent = $this->makeAgent(['name' => 'Coordinator']);
    $this->makeRun(['agent_id' => $agent->getKey()]);

    Livewire::test(RunsIndex::class)
        ->assertOk()
        ->assertSee('Coordinator');
});

it('shows the whole run id, because a truncated one cannot be matched to a log', function (): void {
    $run = $this->makeRun();

    Livewire::test(RunsIndex::class)
        ->assertOk()
        ->assertSee((string) $run->getKey());
});

it('polls while a run can still change, and stops once none can', function (): void {
    $run = $this->makeRun(['state' => RunState::Running->value]);

    Livewire::test(RunsIndex::class)->assertSeeHtml('wire:poll');

    $run->update(['state' => RunState::Completed->value]);

    Livewire::test(RunsIndex::class)->assertDontSeeHtml('wire:poll');
});

it('paginates with Pandora markup rather than the framework default', function (): void {
    // Found in the browser: the list rendered a chevron the height of the
    // viewport and two paginators, one above the table and one far below it.
    //
    // Laravel's default pagination view is written for Tailwind. Pandora ships
    // its own CSS and no Tailwind, so the utility classes that hide one of the
    // view's two blocks do nothing, and its inline SVG chevrons -- sized
    // entirely by those classes -- render unbounded. A page that merely
    // "renders the paginator" asserts none of that, which is why this asserts
    // WHICH paginator.
    foreach (range(1, 30) as $ignored) {
        $this->makeRun(['state' => RunState::Completed->value]);
    }

    $html = Livewire::test(RunsIndex::class)->assertOk()->html();

    expect($html)->toContain('pd-pagination')
        // The two things the default view brings and this design system cannot
        // size: an inline SVG, and Tailwind's responsive visibility utilities.
        ->and($html)->not->toContain('<svg')
        ->and($html)->not->toContain('sm:hidden')
        ->and($html)->not->toContain('hidden sm:');
});
