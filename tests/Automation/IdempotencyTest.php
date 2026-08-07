<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Pandora\Automation\AutomationRun;
use Pandora\Jobs\RunAutomation;
use Pandora\Runs\Run;
use Pandora\Tests\Fixtures\AutomationFactory;

/**
 * Phase 4, criterion 6 -- a retried job does nothing twice.
 *
 * Queues deliver at least once. That is not a defect to work around; it is the
 * contract, and it means every job Pandora writes has to be safe to run twice.
 * For this one the safety is the occurrence key: it travels ON the payload
 * rather than being recomputed, so a retry claims the same key, the unique
 * index refuses it, and the dispatcher answers null.
 */
it('creates no second run when the job is retried for the same occurrence', function (): void {
    $automation = AutomationFactory::due();
    $occurrence = Carbon::parse('2026-07-01 09:00:00', 'UTC');
    $key = AutomationRun::keyFor((string) $automation->getKey(), $occurrence);

    $job = new RunAutomation(
        automationId: (string) $automation->getKey(),
        tenantId: $automation->tenant_id,
        occurrence: $occurrence->toIso8601String(),
        idempotencyKey: $key,
    );

    dispatch_sync($job);
    dispatch_sync($job);
    dispatch_sync($job);

    expect(AutomationRun::query()->where('automation_id', $automation->getKey())->count())->toBe(1)
        ->and(Run::query()->where('automation_id', $automation->getKey())->count())->toBe(1);
});

it('derives the same key from the same occurrence, and a different one otherwise', function (): void {
    // The property two schedulers depend on. A random key would make both
    // inserts succeed, which is the bug this whole design exists to prevent.
    $id = 'automation-abc';
    $at = Carbon::parse('2026-07-01 09:00:00', 'UTC');

    expect(AutomationRun::keyFor($id, $at))
        ->toBe(AutomationRun::keyFor($id, $at->copy()))
        // The same instant expressed in another zone is the same occurrence.
        ->toBe(AutomationRun::keyFor($id, $at->copy()->setTimezone('Europe/Copenhagen')))
        ->not->toBe(AutomationRun::keyFor($id, $at->copy()->addMinute()))
        ->not->toBe(AutomationRun::keyFor('automation-def', $at));
});

it('ignores sub-second drift between two schedulers', function (): void {
    // Second resolution is deliberate: no schedule Pandora supports has two
    // occurrences in the same second, and a millisecond of clock drift
    // between two machines must not mint a second key.
    $id = 'automation-abc';
    $at = Carbon::parse('2026-07-01 09:00:00.000', 'UTC');

    expect(AutomationRun::keyFor($id, $at))
        ->toBe(AutomationRun::keyFor($id, $at->copy()->addMilliseconds(400)));
});

it('does nothing when the automation was disabled between the tick and the worker', function (): void {
    $automation = AutomationFactory::due();

    $automation->forceFill(['enabled' => false])->save();

    dispatch_sync(new RunAutomation(
        automationId: (string) $automation->getKey(),
        occurrence: Carbon::now()->toIso8601String(),
    ));

    // An ordinary race, and the quiet answer is the right one -- there is
    // nothing to record about an occurrence that never got as far as a claim.
    expect(AutomationRun::query()->count())->toBe(0);
});

it('does nothing when the automation was deleted between the tick and the worker', function (): void {
    $automation = AutomationFactory::due();
    $id = (string) $automation->getKey();

    $automation->delete();

    dispatch_sync(new RunAutomation(automationId: $id, occurrence: Carbon::now()->toIso8601String()));

    expect(AutomationRun::query()->count())->toBe(0);
});
