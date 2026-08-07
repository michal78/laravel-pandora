<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Pandora\Automation\Enums\AutomationTrigger;
use Pandora\Automation\Schedule\NextRun;
use Pandora\Exceptions\InvalidConfiguration;
use Pandora\Tests\Fixtures\AutomationFactory;

/**
 * Phase 4, criteria 1-3 -- when an automation is next due.
 *
 * The timezone tests are the ones worth having. A "9am daily" schedule
 * configured by somebody in Copenhagen and stored against a UTC server is
 * correct for months and then wrong for a day, twice a year, which is
 * experienced as Pandora being unreliable rather than as a timezone bug.
 */
beforeEach(function (): void {
    $this->nextRun = app(NextRun::class);
});

// ---------------------------------------------------------------- criterion 1

it('computes cron occurrences in the automation\'s own timezone, not the server\'s', function (): void {
    // 09:00 in Copenhagen is 07:00 UTC in summer.
    $automation = AutomationFactory::make([
        'cron_expression' => '0 9 * * *',
        'timezone' => 'Europe/Copenhagen',
    ]);

    $next = $this->nextRun->after($automation, Carbon::parse('2026-07-01 00:00:00', 'UTC'));

    expect($next)->not->toBeNull()
        ->and($next->utc()->format('Y-m-d H:i'))->toBe('2026-07-01 07:00')
        ->and($next->copy()->setTimezone('Europe/Copenhagen')->format('H:i'))->toBe('09:00');
});

it('gives a UTC automation the hour it asked for, unshifted', function (): void {
    $automation = AutomationFactory::make([
        'cron_expression' => '0 9 * * *',
        'timezone' => 'UTC',
    ]);

    $next = $this->nextRun->after($automation, Carbon::parse('2026-07-01 00:00:00', 'UTC'));

    expect($next->utc()->format('Y-m-d H:i'))->toBe('2026-07-01 09:00');
});

// ---------------------------------------------------------------- criterion 2

it('computes an interval occurrence from the moment asked about', function (): void {
    $automation = AutomationFactory::make([
        'trigger_type' => AutomationTrigger::Interval->value,
        'cron_expression' => null,
        'interval_seconds' => 900,
    ]);

    $from = Carbon::parse('2026-07-01 10:00:00', 'UTC');

    expect($this->nextRun->after($automation, $from)->utc()->format('H:i'))->toBe('10:15');
});

it('gives a one-off its moment, and nothing after it has passed', function (): void {
    $automation = AutomationFactory::make([
        'trigger_type' => AutomationTrigger::OneOff->value,
        'cron_expression' => null,
        'run_at' => Carbon::parse('2026-07-01 12:00:00', 'UTC'),
    ]);

    expect($this->nextRun->after($automation, Carbon::parse('2026-07-01 09:00:00', 'UTC'))?->utc()->format('H:i'))
        ->toBe('12:00')
        // Once it has fired there is no next occurrence. This is what stops a
        // one-off becoming an every-tick.
        ->and($this->nextRun->after($automation, Carbon::parse('2026-07-01 12:00:01', 'UTC')))
        ->toBeNull();
});

it('gives an event or webhook automation no schedule at all', function (): void {
    // The scheduler's query is `next_run_at <= now`. An externally-woken
    // automation with a date in that column would be claimed by the clock as
    // well as by its trigger, and fire twice for different reasons.
    foreach ([AutomationTrigger::Event, AutomationTrigger::Webhook] as $trigger) {
        $automation = AutomationFactory::make([
            'slug' => 'ext-'.$trigger->value,
            'trigger_type' => $trigger->value,
            'event_class' => 'App\\Events\\Whatever',
        ]);

        expect($this->nextRun->after($automation))->toBeNull();
    }
});

it('lets a heartbeat be expressed as an interval or as cron', function (): void {
    $interval = AutomationFactory::make([
        'trigger_type' => AutomationTrigger::Heartbeat->value,
        'cron_expression' => null,
        'interval_seconds' => 3600,
    ]);

    $cron = AutomationFactory::make([
        'slug' => 'heartbeat-cron',
        'trigger_type' => AutomationTrigger::Heartbeat->value,
        'cron_expression' => '30 * * * *',
    ]);

    $from = Carbon::parse('2026-07-01 10:00:00', 'UTC');

    expect($this->nextRun->after($interval, $from)->utc()->format('H:i'))->toBe('11:00')
        ->and($this->nextRun->after($cron, $from)->utc()->format('H:i'))->toBe('10:30');
});

// ---------------------------------------------------------------- criterion 3

it('neither skips nor repeats a daily occurrence across a DST transition', function (): void {
    // Europe/Copenhagen springs forward at 02:00 on 2026-03-29 and falls back
    // at 03:00 on 2026-10-25. A 09:00 daily schedule must produce exactly one
    // occurrence on each of those days, at 09:00 local -- which is a DIFFERENT
    // UTC hour either side of the boundary. That shift is the correct answer,
    // and a naive UTC calculation gets it wrong in the direction nobody
    // notices until a report arrives an hour late.
    $automation = AutomationFactory::make([
        'cron_expression' => '0 9 * * *',
        'timezone' => 'Europe/Copenhagen',
    ]);

    $spring = $this->nextRun->occurrencesBetween(
        $automation,
        Carbon::parse('2026-03-28 00:00:00', 'UTC'),
        Carbon::parse('2026-03-31 00:00:00', 'UTC'),
        10,
    );

    $local = array_map(
        static fn (Carbon $at): string => $at->copy()->setTimezone('Europe/Copenhagen')->format('Y-m-d H:i'),
        $spring,
    );

    expect($local)->toBe([
        '2026-03-28 09:00',
        '2026-03-29 09:00',
        '2026-03-30 09:00',
    ]);

    // The same three days in UTC show the shift: 08:00 before the transition,
    // 07:00 after it. One occurrence per day throughout.
    expect(array_map(static fn (Carbon $at): string => $at->utc()->format('H:i'), $spring))
        ->toBe(['08:00', '07:00', '07:00']);
});

it('produces one occurrence per day across the autumn transition too', function (): void {
    $automation = AutomationFactory::make([
        'cron_expression' => '0 9 * * *',
        'timezone' => 'Europe/Copenhagen',
    ]);

    $autumn = $this->nextRun->occurrencesBetween(
        $automation,
        Carbon::parse('2026-10-24 00:00:00', 'UTC'),
        Carbon::parse('2026-10-27 00:00:00', 'UTC'),
        10,
    );

    expect($autumn)->toHaveCount(3)
        ->and(array_map(
            static fn (Carbon $at): string => $at->copy()->setTimezone('Europe/Copenhagen')->format('H:i'),
            $autumn,
        ))->toBe(['09:00', '09:00', '09:00']);
});

// ---------------------------------------------------------------- validation

it('refuses a schedule it could not compute, rather than storing one that never fires', function (): void {
    // The failure this prevents: an unparseable expression is stored,
    // `next_run_at` comes out null, and the automation presents as one that
    // simply never runs -- the hardest kind of failure to notice, because
    // there is nothing in any log to notice.
    $bad = AutomationFactory::make(['cron_expression' => 'every tuesday-ish']);

    expect(fn (): mixed => $this->nextRun->validate($bad))
        ->toThrow(InvalidConfiguration::class);

    $noInterval = AutomationFactory::make([
        'slug' => 'no-interval',
        'trigger_type' => AutomationTrigger::Interval->value,
        'cron_expression' => null,
        'interval_seconds' => null,
    ]);

    expect(fn (): mixed => $this->nextRun->validate($noInterval))
        ->toThrow(InvalidConfiguration::class);

    $badZone = AutomationFactory::make(['slug' => 'bad-zone', 'timezone' => 'Middle/Earth']);

    expect(fn (): mixed => $this->nextRun->validate($badZone))
        ->toThrow(InvalidConfiguration::class);
});

it('accepts a schedule it can compute', function (): void {
    $automation = AutomationFactory::make([
        'cron_expression' => '*/15 * * * *',
        'timezone' => 'Europe/Copenhagen',
    ]);

    $this->nextRun->validate($automation);

    expect($this->nextRun->after($automation))->not->toBeNull();
});
