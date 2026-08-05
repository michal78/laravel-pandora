<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Pandora\Pandora\Automation\AutomationDispatcher;
use Pandora\Pandora\Automation\Enums\ConcurrencyPolicy;
use Pandora\Pandora\Automation\Enums\OccurrenceStatus;
use Pandora\Pandora\Runs\Enums\RunState;
use Pandora\Pandora\Runs\Run;
use Pandora\Pandora\Tests\Fixtures\AgentFactory;
use Pandora\Pandora\Tests\Fixtures\AutomationFactory;

/**
 * Phase 4, criterion 10 -- overlapping runs.
 *
 * `skip` is the default because `allow` fails silently and cumulatively: an
 * hourly automation whose run takes ninety minutes accumulates workers until
 * the queue stops moving, and the only symptom is that everything else got
 * slow.
 */
beforeEach(function (): void {
    $this->dispatcher = app(AutomationDispatcher::class);
});

it('refuses an occurrence while a previous run of the same automation is live', function (): void {
    $automation = AutomationFactory::due(['concurrency_policy' => ConcurrencyPolicy::Skip->value]);

    $first = $this->dispatcher->dispatch($automation, Carbon::parse('2026-07-01 09:00:00'));

    // The first run is still going -- a `sync` queue would have finished it,
    // so it is held in a live state deliberately.
    Run::query()->whereKey($first->run_id)->update(['state' => RunState::Running->value]);

    $second = $this->dispatcher->dispatch($automation->refresh(), Carbon::parse('2026-07-01 10:00:00'));

    expect($second->status)->toBe(OccurrenceStatus::Refused)
        ->and($second->reason)->toBe('concurrency')
        ->and($second->run_id)->toBeNull();
});

it('allows the overlap when the policy says so', function (): void {
    $automation = AutomationFactory::due(['concurrency_policy' => ConcurrencyPolicy::Allow->value]);

    $first = $this->dispatcher->dispatch($automation, Carbon::parse('2026-07-01 09:00:00'));
    Run::query()->whereKey($first->run_id)->update(['state' => RunState::Running->value]);

    $second = $this->dispatcher->dispatch($automation->refresh(), Carbon::parse('2026-07-01 10:00:00'));

    expect($second->status)->toBe(OccurrenceStatus::Dispatched)
        ->and($second->run_id)->not->toBeNull();
});

it('cancels the run in flight under cancel_previous', function (): void {
    $automation = AutomationFactory::due([
        'concurrency_policy' => ConcurrencyPolicy::CancelPrevious->value,
    ]);

    $first = $this->dispatcher->dispatch($automation, Carbon::parse('2026-07-01 09:00:00'));
    Run::query()->whereKey($first->run_id)->update(['state' => RunState::Running->value]);

    $second = $this->dispatcher->dispatch($automation->refresh(), Carbon::parse('2026-07-01 10:00:00'));

    /** @var Run $superseded */
    $superseded = Run::query()->findOrFail($first->run_id);

    expect($second->status)->toBe(OccurrenceStatus::Dispatched)
        // Requested, not forced: an in-flight tool call still finishes,
        // because killing one mid-write is worse than letting it complete.
        ->and($superseded->cancel_requested_at)->not->toBeNull();
});

it('is not blocked by somebody typing to the same agent', function (): void {
    // The policy is about runs THIS automation started. An automation that
    // shares an agent with the chat page must not stop firing because a user
    // is mid-conversation.
    $agent = AgentFactory::database(['slug' => 'shared', 'autonomy_level' => 'act_within_policy']);
    $automation = AutomationFactory::due(['concurrency_policy' => ConcurrencyPolicy::Skip->value], $agent);

    $interactive = Run::query()->create([
        'agent_id' => $agent->getKey(),
        'session_id' => (string) Str::ulid(),
        'state' => RunState::Running->value,
        'trigger_type' => 'user_message',
        'correlation_id' => (string) Str::ulid(),
        'currency' => 'USD',
    ]);

    $occurrence = $this->dispatcher->dispatch($automation, Carbon::now());

    expect($occurrence->status)->toBe(OccurrenceStatus::Dispatched)
        ->and($interactive->refresh()->cancel_requested_at)->toBeNull();
});

it('is not blocked by a run of the same automation that has finished', function (): void {
    $automation = AutomationFactory::due(['concurrency_policy' => ConcurrencyPolicy::Skip->value]);

    $first = $this->dispatcher->dispatch($automation, Carbon::parse('2026-07-01 09:00:00'));
    Run::query()->whereKey($first->run_id)->update(['state' => RunState::Completed->value]);

    $second = $this->dispatcher->dispatch($automation->refresh(), Carbon::parse('2026-07-01 10:00:00'));

    expect($second->status)->toBe(OccurrenceStatus::Dispatched);
});
