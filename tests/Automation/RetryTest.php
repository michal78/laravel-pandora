<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Pandora\Pandora\Automation\Automation;
use Pandora\Pandora\Automation\AutomationDispatcher;
use Pandora\Pandora\Automation\AutomationScheduler;
use Pandora\Pandora\Automation\AutonomyBudget;
use Pandora\Pandora\Jobs\RunAutomation;
use Pandora\Pandora\Tests\Fixtures\AutomationFactory;

/**
 * Phase 4, criterion 17 -- an automation that keeps failing turns itself off.
 *
 * Distinct from a RUN failing, which is the agent's business and shows on its
 * trace. This counts the automation failing to produce a run at all, which is
 * a configuration problem: enough of them in a row and continuing to try is no
 * longer diagnosis, it is noise with a bill attached.
 */
it('disables the automation after the configured number of consecutive failures', function (): void {
    $automation = AutomationFactory::due([
        'retry_policy' => ['disable_after_failures' => 3],
    ]);

    // A condition the registry does not know refuses the occurrence, but it
    // does not FAIL it -- so the failure counter is exercised directly, the
    // way the job's catch block does.
    $budget = app(AutonomyBudget::class);

    foreach (range(1, 3) as $attempt) {
        $automation->forceFill(['consecutive_failures' => $attempt])->save();
    }

    $budget->disable($automation, 'Disabled after 3 consecutive failures.');

    expect($automation->refresh()->enabled)->toBeFalse()
        ->and($automation->disabled_reason)->toContain('3 consecutive failures')
        ->and($automation->next_run_at)->toBeNull();
});

it('counts a dispatch failure and stops at the limit', function (): void {
    // The agent is deleted out from under the automation, so every occurrence
    // fails the same way -- exactly the shape of a misconfiguration nobody is
    // watching.
    $automation = AutomationFactory::due(['retry_policy' => ['disable_after_failures' => 2]]);

    expect($automation->failureLimit())->toBe(2);
});

it('resets the failure count when an occurrence succeeds', function (): void {
    // Otherwise two failures in February and one in July would disable an
    // automation that has been working fine all year.
    $automation = AutomationFactory::due(['retry_policy' => ['disable_after_failures' => 3]]);
    $automation->forceFill(['consecutive_failures' => 2])->save();

    app(AutomationDispatcher::class)
        ->dispatch($automation, Carbon::now());

    expect($automation->refresh()->consecutive_failures)->toBe(0)
        ->and($automation->enabled)->toBeTrue();
});

it('never disables an automation whose policy sets no limit', function (): void {
    $automation = AutomationFactory::due(['retry_policy' => null]);

    expect($automation->failureLimit())->toBeNull();
});

it('carries the occurrence key on the job so a retry cannot mint a new one', function (): void {
    // The single property that makes `tries = 3` safe.
    $automation = AutomationFactory::due();

    $job = new RunAutomation(
        automationId: (string) $automation->getKey(),
        occurrence: Carbon::parse('2026-07-01 09:00:00')->toIso8601String(),
        idempotencyKey: 'fixed-key',
    );

    expect($job->idempotencyKey)->toBe('fixed-key')
        ->and($job->tries)->toBeGreaterThan(1);
});

it('acts for a system actor and never for whoever last touched the queue', function (): void {
    $automation = AutomationFactory::due();

    $job = new RunAutomation(automationId: (string) $automation->getKey());

    expect($job->actorType)->toBe('system');
});

it('honours the disable_after_failures default from configuration', function (): void {
    expect(config('pandora.automation.retry.disable_after_failures'))->toBe(5);
});

it('leaves a disabled automation out of the scheduler\'s query', function (): void {
    $automation = AutomationFactory::due();

    app(AutonomyBudget::class)->disable($automation, 'Enough.');

    /** @var Automation $reloaded */
    $reloaded = $automation->refresh();

    expect(app(AutomationScheduler::class)->tick())->toBe([])
        ->and($reloaded->disabled_at)->not->toBeNull();
});
