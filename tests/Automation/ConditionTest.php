<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Pandora\Automation\AutomationDispatcher;
use Pandora\Automation\ConditionRegistry;
use Pandora\Automation\Enums\OccurrenceStatus;
use Pandora\Exceptions\AutomationRefused;
use Pandora\Runs\Run;
use Pandora\Tests\Fixtures\AlwaysTrueCondition;
use Pandora\Tests\Fixtures\AutomationFactory;

/**
 * Phase 4, criteria 11 and 12 -- conditional polling.
 *
 * The important one is 12. A condition NAMED in a database row and DEFINED in
 * the host's config file is the same rule as tools, jobs and readable config
 * keys, and for the same reason: a callable read out of a row is remote code
 * execution with extra steps, and an automations page is exactly the surface
 * an attacker would want it on.
 */
beforeEach(function (): void {
    $this->dispatcher = app(AutomationDispatcher::class);
});

// ---------------------------------------------------------------- criterion 11

it('creates no run when the condition is false, and records the skip', function (): void {
    config()->set('pandora.automation.conditions', [
        'anything_to_do' => static fn (array $arguments): bool => false,
    ]);

    $automation = AutomationFactory::due(['condition' => ['name' => 'anything_to_do']]);

    $occurrence = $this->dispatcher->dispatch($automation, Carbon::now());

    expect($occurrence->status)->toBe(OccurrenceStatus::Skipped)
        ->and($occurrence->reason)->toBe('condition')
        ->and($occurrence->run_id)->toBeNull()
        ->and(Run::query()->where('automation_id', $automation->getKey())->count())->toBe(0);
});

it('creates a run when the condition is true', function (): void {
    config()->set('pandora.automation.conditions', [
        'anything_to_do' => static fn (array $arguments): bool => true,
    ]);

    $automation = AutomationFactory::due(['condition' => ['name' => 'anything_to_do']]);

    expect($this->dispatcher->dispatch($automation, Carbon::now())->status)
        ->toBe(OccurrenceStatus::Dispatched);
});

it('passes the automation\'s arguments to the condition', function (): void {
    $seen = [];

    config()->set('pandora.automation.conditions', [
        'over_threshold' => static function (array $arguments) use (&$seen): bool {
            $seen = $arguments;

            return ($arguments['threshold'] ?? 0) < 10;
        },
    ]);

    $automation = AutomationFactory::due([
        'condition' => ['name' => 'over_threshold', 'arguments' => ['threshold' => 5]],
    ]);

    $occurrence = $this->dispatcher->dispatch($automation, Carbon::now());

    expect($seen)->toBe(['threshold' => 5])
        ->and($occurrence->status)->toBe(OccurrenceStatus::Dispatched);
});

it('treats an automation with no condition as unconditional', function (): void {
    $automation = AutomationFactory::due(['condition' => null]);

    expect($this->dispatcher->dispatch($automation, Carbon::now())->status)
        ->toBe(OccurrenceStatus::Dispatched);
});

// ---------------------------------------------------------------- criterion 12

it('refuses rather than evaluating a condition it does not recognise', function (): void {
    // It does not evaluate true and it does not evaluate false. An automation
    // whose condition was renamed out from under it must stop, not guess --
    // and it certainly must not treat the name as something to call.
    config()->set('pandora.automation.conditions', []);

    $automation = AutomationFactory::due([
        'condition' => ['name' => 'App\\Evil::run'],
    ]);

    $occurrence = $this->dispatcher->dispatch($automation, Carbon::now());

    expect($occurrence->status)->toBe(OccurrenceStatus::Skipped)
        ->and($occurrence->reason)->toBe('unknown_condition')
        ->and($occurrence->error)->toContain('not registered')
        ->and($occurrence->run_id)->toBeNull();
});

it('never treats a value from the database as a callable', function (): void {
    $registry = app(ConditionRegistry::class);

    config()->set('pandora.automation.conditions', []);

    // Every shape somebody might hope would execute: a function name, a
    // static call, a class with __invoke. None of them are registered, so
    // none of them are reached.
    foreach (['phpinfo', 'App\\Evil::run', 'system'] as $name) {
        $automation = AutomationFactory::make([
            'slug' => 'cond-'.md5($name),
            'condition' => ['name' => $name],
        ]);

        expect(fn (): bool => $registry->evaluate($automation))
            ->toThrow(AutomationRefused::class);
    }
});

it('accepts a registered invokable class as a condition', function (): void {
    config()->set('pandora.automation.conditions', [
        'always' => AlwaysTrueCondition::class,
    ]);

    $automation = AutomationFactory::due(['condition' => ['name' => 'always']]);

    expect($this->dispatcher->dispatch($automation, Carbon::now())->status)
        ->toBe(OccurrenceStatus::Dispatched);
});
