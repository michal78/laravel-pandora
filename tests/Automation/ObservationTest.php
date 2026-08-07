<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Pandora\Agents\Agent;
use Pandora\Audit\AuditLog;
use Pandora\Automation\Automation;
use Pandora\Automation\Enums\AutomationTrigger;
use Pandora\Automation\Enums\ObservationStatus;
use Pandora\Automation\Observation;
use Pandora\Automation\ObservationManager;
use Pandora\Conversations\Session;
use Pandora\Core\Actor\ActorContext;
use Pandora\Exceptions\ObservationNotPending;
use Pandora\Providers\Data\ToolCall;
use Pandora\Runs\Enums\AutonomyLevel;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Run;
use Pandora\Tests\Fixtures\AgentFactory;
use Pandora\Tools\BuiltIn\ProposeFollowUpTool;
use Pandora\Tools\ToolContext;
use Pandora\Tools\ToolGatekeeper;
use Pandora\Tools\ToolInput;
use Pandora\Tools\ToolRegistry;

/**
 * Phase 4, criteria 24 and 25 -- the goal queue.
 *
 * The asymmetry is the feature. An agent may notice that the weekly
 * reconciliation would be worth running on Mondays and say so; it may not put
 * that in the scheduler. The parity matrix classes autonomous promotion as
 * Future for the same reason ADR-0009 exists.
 */
beforeEach(function (): void {
    Gate::define('pandora.automations.manage', static fn (): bool => true);

    // A real user, so the gate is genuinely consulted. Laravel denies an
    // unauthenticated request before the callback runs, which would make every
    // "refuses without the ability" test below pass for the wrong reason.
    $this->actingAsUser();

    $this->agent = AgentFactory::database([
        'slug' => 'observer',
        'autonomy_level' => AutonomyLevel::ObserveOnly->value,
        'tool_policy' => ['allow' => ['propose_follow_up']],
    ]);
});

function proposalContext(Agent $agent, ?AutonomyLevel $level = null): ToolContext
{
    /** @var Run $run */
    $run = Run::query()->create([
        'agent_id' => $agent->getKey(),
        'session_id' => (string) Str::ulid(),
        'state' => RunState::Running->value,
        'trigger_type' => 'schedule',
        'autonomy_level' => $level?->value,
        'correlation_id' => (string) Str::ulid(),
        'currency' => 'USD',
    ]);

    return new ToolContext(
        run: $run,
        agent: $agent,
        session: new Session(['agent_id' => $agent->getKey()]),
        // Deliberately a SYSTEM actor: an autonomous run is precisely the one
        // most likely to notice something worth proposing, and the base
        // class's default would deny it.
        actor: ActorContext::system('automation:nightly'),
        toolCallId: 'call-1',
    );
}

// ---------------------------------------------------------------- criterion 24

it('writes a pending observation and schedules nothing', function (): void {
    $context = proposalContext($this->agent, AutonomyLevel::ObserveOnly);

    $result = (new ProposeFollowUpTool)->handle(
        new ToolInput([
            'title' => 'Weekly reconciliation',
            'proposal' => 'Reconcile last week\'s payouts and report anything unmatched.',
            'rationale' => 'Three unmatched payouts turned up this week.',
            'suggested_schedule' => '0 9 * * 1',
        ]),
        $context,
    );

    /** @var Observation $observation */
    $observation = Observation::query()->firstOrFail();

    expect($observation->status)->toBe(ObservationStatus::Pending)
        ->and($observation->agent_id)->toBe($this->agent->getKey())
        // Provenance. An observation nobody can trace back to a run is an
        // anonymous instruction, and nobody should promote one of those.
        ->and($observation->run_id)->toBe($context->runId())
        ->and($observation->suggested_cron)->toBe('0 9 * * 1')
        ->and($observation->expires_at)->not->toBeNull()
        // Nothing was scheduled.
        ->and(Automation::query()->count())->toBe(0)
        // And the model is told so plainly, or it will report to the user
        // that it has set something up.
        ->and($result->content)->toContain('Nothing has been scheduled');
});

it('is available to an observe_only run, because proposing changes nothing', function (): void {
    // The point of `observe_only` is "watch, and tell me". An agent that could
    // not even propose would have nothing to do with what it noticed.
    //
    // Already registered: it is a built-in, so it is installed on every
    // deployment -- and still granted to nobody who has not named it.
    expect(app(ToolRegistry::class)->find('propose_follow_up'))->not->toBeNull();

    $decision = app(ToolGatekeeper::class)->evaluate(
        new ToolCall('call-1', 'propose_follow_up', [
            'title' => 'Weekly reconciliation',
            'proposal' => 'Reconcile last week\'s payouts and report.',
        ]),
        proposalContext($this->agent, AutonomyLevel::ObserveOnly),
    );

    expect($decision->isAllowed())->toBeTrue();
});

it('records the proposal in the audit log', function (): void {
    (new ProposeFollowUpTool)->handle(
        new ToolInput(['title' => 'Something', 'proposal' => 'Do the thing weekly.']),
        proposalContext($this->agent),
    );

    expect(AuditLog::query()->pluck('action')->all())->toContain('observation.proposed');
});

// ---------------------------------------------------------------- criterion 25

it('promotes a proposal into a DISABLED one-off automation', function (): void {
    $observation = pendingObservation($this->agent);

    $automation = app(ObservationManager::class)->promote($observation);

    expect($automation->enabled)->toBeFalse()
        ->and($automation->trigger_type)->toBe(AutomationTrigger::OneOff)
        // The most restrictive level, whatever the agent has: approving an
        // idea is not approving the agent acting on it.
        ->and($automation->autonomy_level)->toBe(AutonomyLevel::ObserveOnly)
        // Verbatim. Paraphrasing would mean the thing that runs is not the
        // thing that was reviewed.
        ->and($automation->prompt)->toBe($observation->proposal)
        // Advisory, and carried across for the editor rather than obeyed.
        ->and($automation->cron_expression)->toBeNull()
        ->and($automation->metadata['suggested_cron'])->toBe('0 9 * * 1')
        ->and($observation->refresh()->status)->toBe(ObservationStatus::Promoted)
        ->and($observation->automation_id)->toBe($automation->getKey());
});

it('refuses to promote without pandora.automations.manage', function (): void {
    Gate::define('pandora.automations.manage', static fn (): bool => false);

    $observation = pendingObservation($this->agent);

    expect(fn (): Automation => app(ObservationManager::class)->promote($observation))
        ->toThrow(AuthorizationException::class);

    expect(Automation::query()->count())->toBe(0)
        ->and($observation->refresh()->status)->toBe(ObservationStatus::Pending);
});

it('refuses to promote the same proposal twice', function (): void {
    // Two operators looking at the same queue is the normal case, and the
    // second one to press Promote deserves an explanation rather than a
    // second automation.
    $observation = pendingObservation($this->agent);

    app(ObservationManager::class)->promote($observation);

    expect(fn (): Automation => app(ObservationManager::class)->promote($observation->refresh()))
        ->toThrow(ObservationNotPending::class);

    expect(Automation::query()->count())->toBe(1);
});

it('gives two proposals of the same name distinct slugs', function (): void {
    app(ObservationManager::class)->promote(pendingObservation($this->agent));
    app(ObservationManager::class)->promote(pendingObservation($this->agent));

    expect(Automation::query()->pluck('slug')->unique())->toHaveCount(2);
});

it('dismisses a proposal with a comment, and audits it', function (): void {
    $observation = pendingObservation($this->agent);

    app(ObservationManager::class)->dismiss($observation, 'We already do this by hand.');

    expect($observation->refresh()->status)->toBe(ObservationStatus::Dismissed)
        ->and($observation->comment)->toBe('We already do this by hand.')
        ->and($observation->resolved_at)->not->toBeNull()
        ->and(AuditLog::query()->pluck('action')->all())->toContain('observation.dismissed');
});

it('refuses to dismiss without pandora.automations.manage', function (): void {
    Gate::define('pandora.automations.manage', static fn (): bool => false);

    $observation = pendingObservation($this->agent);

    expect(fn (): Observation => app(ObservationManager::class)->dismiss($observation))
        ->toThrow(AuthorizationException::class);
});

it('expires proposals nobody looked at', function (): void {
    $stale = pendingObservation($this->agent);
    $stale->forceFill(['expires_at' => now()->subDay()])->save();

    $fresh = pendingObservation($this->agent);

    expect(app(ObservationManager::class)->expire())->toBe(1)
        ->and($stale->refresh()->status)->toBe(ObservationStatus::Expired)
        ->and($fresh->refresh()->status)->toBe(ObservationStatus::Pending);
});

// ------------------------------------------------------------------ helpers

function pendingObservation(Agent $agent): Observation
{
    /** @var Observation $observation */
    $observation = Observation::query()->create([
        'agent_id' => $agent->getKey(),
        'title' => 'Weekly reconciliation',
        'proposal' => 'Reconcile last week\'s payouts and report anything unmatched.',
        'rationale' => 'Three unmatched payouts turned up this week.',
        'suggested_cron' => '0 9 * * 1',
        'status' => ObservationStatus::Pending->value,
        'expires_at' => now()->addDays(30),
    ]);

    return $observation;
}
