<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Pandora\Automation\AutomationScheduler;
use Pandora\Automation\Enums\MisfirePolicy;
use Pandora\Jobs\RunAutomation;
use Pandora\Tests\Fixtures\AutomationFactory;

/**
 * Phase 4, criterion 9 -- what happens to occurrences nobody was there for.
 *
 * The default is `skip`, and it is the default because the alternative is
 * memorable: a worker down for six hours comes back to three hundred and sixty
 * queued runs, every one of them stale, every one of them costing money to
 * discover it is stale.
 */
beforeEach(function (): void {
    $this->scheduler = app(AutomationScheduler::class);

    Bus::fake();
});

it('drops missed occurrences under the skip policy', function (): void {
    $automation = AutomationFactory::make([
        'cron_expression' => '*/10 * * * *',
        'misfire_policy' => MisfirePolicy::Skip->value,
    ]);

    // Six hours late: an outage, not a slow minute.
    $automation->forceFill(['next_run_at' => now()->subHours(6)])->save();

    $this->scheduler->tick();

    Bus::assertNotDispatched(RunAutomation::class);

    // And it is scheduled again from now, so the automation resumes rather
    // than staying permanently behind.
    expect($automation->refresh()->next_run_at->isFuture())->toBeTrue();
});

it('catches up with exactly one occurrence under run_once', function (): void {
    $automation = AutomationFactory::make([
        'cron_expression' => '*/10 * * * *',
        'misfire_policy' => MisfirePolicy::RunOnce->value,
    ]);

    $automation->forceFill(['next_run_at' => now()->subHours(6)])->save();

    $this->scheduler->tick();

    // Thirty-six occurrences were missed. The thing it was supposed to do, it
    // does -- once.
    Bus::assertDispatchedTimes(RunAutomation::class, 1);
});

it('bounds run_all by the configured cap', function (): void {
    config()->set('pandora.automation.misfire.max_catch_up', 4);

    $automation = AutomationFactory::make([
        'cron_expression' => '*/10 * * * *',
        'misfire_policy' => MisfirePolicy::RunAll->value,
    ]);

    $automation->forceFill(['next_run_at' => now()->subHours(6)])->save();

    $this->scheduler->tick();

    // Thirty-six were missed; four is what the deployment said it would
    // tolerate. An unbounded catch-up is the outage twice, and the second time
    // it is self-inflicted.
    Bus::assertDispatchedTimes(RunAutomation::class, 4);
});

it('gives every caught-up occurrence a distinct key', function (): void {
    config()->set('pandora.automation.misfire.max_catch_up', 3);

    $automation = AutomationFactory::make([
        'cron_expression' => '*/10 * * * *',
        'misfire_policy' => MisfirePolicy::RunAll->value,
    ]);

    $automation->forceFill(['next_run_at' => now()->subHour()])->save();

    // Otherwise the catch-up would claim the same key three times and produce
    // one run -- silently turning run_all into run_once.
    expect(array_unique($this->scheduler->tick()))->toHaveCount(3);
});

it('does not treat a slow minute as a misfire', function (): void {
    // The grace window exists so that a tick arriving 40 seconds late is an
    // ordinary occurrence rather than an incident with a policy attached.
    $automation = AutomationFactory::make([
        'cron_expression' => '*/10 * * * *',
        'misfire_policy' => MisfirePolicy::Skip->value,
    ]);

    $automation->forceFill(['next_run_at' => now()->subSeconds(40)])->save();

    $this->scheduler->tick();

    Bus::assertDispatchedTimes(RunAutomation::class, 1);
});
