<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Pandora\Agents\Agent;
use Pandora\Audit\AuditLog;
use Pandora\Automation\AutomationDispatcher;
use Pandora\Automation\Enums\OccurrenceStatus;
use Pandora\Automation\Notifications\AutomationDisabled;
use Pandora\Conversations\Session;
use Pandora\Core\Actor\ActorContext;
use Pandora\Providers\Data\ToolCall;
use Pandora\Runs\Enums\AutonomyLevel;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Run;
use Pandora\Tests\Fixtures\AgentFactory;
use Pandora\Tests\Fixtures\AutomationFactory;
use Pandora\Tests\Fixtures\Tools\LookupOrderTool;
use Pandora\Tests\Fixtures\Tools\UpdateNoteTool;
use Pandora\Tools\Enums\AuthorizationLayer;
use Pandora\Tools\Enums\PolicyOutcome;
use Pandora\Tools\ToolContext;
use Pandora\Tools\ToolGatekeeper;
use Pandora\Tools\ToolRegistry;

/**
 * Phase 4, criteria 14, 15 and 16 -- ADR-0009's leash, at the points it bites.
 *
 * Criterion 14 is the security one. An automation carries an autonomy level,
 * and if that level could exceed the agent's then the Automations page would
 * be a privilege escalation surface: anyone who can schedule an `observe_only`
 * agent could schedule it to act.
 */
beforeEach(function (): void {
    $this->dispatcher = app(AutomationDispatcher::class);
});

// ---------------------------------------------------------------- criterion 14

it('clamps an automation to its agent\'s autonomy, never the reverse', function (): void {
    $agent = AgentFactory::database([
        'slug' => 'cautious',
        'autonomy_level' => AutonomyLevel::Suggest->value,
    ]);

    // The automation asks for more than the agent has.
    $automation = AutomationFactory::due([
        'autonomy_level' => AutonomyLevel::ActWithinPolicy->value,
    ], $agent);

    $occurrence = $this->dispatcher->dispatch($automation, Carbon::now());

    /** @var Run $run */
    $run = Run::query()->findOrFail($occurrence->run_id);

    expect($run->autonomy_level)->toBe(AutonomyLevel::Suggest);
});

it('keeps the automation\'s level when it is the narrower of the two', function (): void {
    $agent = AgentFactory::database([
        'slug' => 'permissive',
        'autonomy_level' => AutonomyLevel::ActWithinPolicy->value,
    ]);

    $automation = AutomationFactory::due([
        'autonomy_level' => AutonomyLevel::ObserveOnly->value,
    ], $agent);

    $occurrence = $this->dispatcher->dispatch($automation, Carbon::now());

    expect(Run::query()->findOrFail($occurrence->run_id)->autonomy_level)
        ->toBe(AutonomyLevel::ObserveOnly);
});

it('agrees on the level whichever of the two is narrower', function (): void {
    $cases = [
        [AutonomyLevel::ObserveOnly, AutonomyLevel::ActWithinPolicy, AutonomyLevel::ObserveOnly],
        [AutonomyLevel::ActWithinPolicy, AutonomyLevel::ObserveOnly, AutonomyLevel::ObserveOnly],
        [AutonomyLevel::Suggest, AutonomyLevel::ActWithApproval, AutonomyLevel::Suggest],
        [AutonomyLevel::ActWithApproval, AutonomyLevel::ActWithApproval, AutonomyLevel::ActWithApproval],
    ];

    foreach ($cases as $i => [$automationLevel, $agentLevel, $expected]) {
        $agent = AgentFactory::database(['slug' => "pair-{$i}", 'autonomy_level' => $agentLevel->value]);
        $automation = AutomationFactory::make([
            'slug' => "pair-auto-{$i}",
            'autonomy_level' => $automationLevel->value,
        ], $agent);

        expect($automation->effectiveAutonomy($agent))->toBe($expected);
    }
});

// ---------------------------------------------------------------- criterion 15

it('denies a mutating tool call inside an observe_only run', function (): void {
    // The clamp lives in ToolGatekeeper rather than ToolPolicy on purpose: a
    // policy is the layer a host REPLACES, and a host that bound its own must
    // not silently lose the leash.
    app(ToolRegistry::class)->register(UpdateNoteTool::class);

    $agent = AgentFactory::database([
        'slug' => 'watcher',
        'tool_policy' => ['allow' => ['update_note']],
    ]);

    $run = automationRunAtLevel($agent, AutonomyLevel::ObserveOnly);

    $decision = app(ToolGatekeeper::class)->evaluate(
        new ToolCall('call-1', 'update_note', ['text' => 'hello']),
        automationToolContext($run, $agent),
    );

    expect($decision->isDenied())->toBeTrue()
        ->and($decision->layer)->toBe(AuthorizationLayer::Autonomy)
        ->and($decision->reason)->toContain('changes something');
});

it('denies a mutating tool call inside a suggest run too', function (): void {
    app(ToolRegistry::class)->register(UpdateNoteTool::class);

    $agent = AgentFactory::database([
        'slug' => 'suggester',
        'tool_policy' => ['allow' => ['update_note']],
    ]);

    $run = automationRunAtLevel($agent, AutonomyLevel::Suggest);

    expect(app(ToolGatekeeper::class)->evaluate(
        new ToolCall('call-1', 'update_note', ['text' => 'hello']),
        automationToolContext($run, $agent),
    )->layer)->toBe(AuthorizationLayer::Autonomy);
});

it('leaves an interactive run alone', function (): void {
    // A null autonomy_level is meaningful, not missing data: a human is right
    // there, and the tool policy and approvals are the boundary.
    app(ToolRegistry::class)->register(UpdateNoteTool::class);

    $agent = AgentFactory::database([
        'slug' => 'interactive',
        'tool_policy' => ['allow' => ['update_note']],
    ]);

    $run = automationRunAtLevel($agent, null);

    expect(app(ToolGatekeeper::class)->evaluate(
        new ToolCall('call-1', 'update_note', ['text' => 'hello']),
        automationToolContext($run, $agent),
    )->layer)->not->toBe(AuthorizationLayer::Autonomy);
});

it('pauses an act_with_approval run for a human on anything mutating', function (): void {
    app(ToolRegistry::class)->register(UpdateNoteTool::class);

    $agent = AgentFactory::database([
        'slug' => 'approver',
        'tool_policy' => ['allow' => ['update_note']],
    ]);

    $run = automationRunAtLevel($agent, AutonomyLevel::ActWithApproval);

    $decision = app(ToolGatekeeper::class)->evaluate(
        new ToolCall('call-1', 'update_note', ['text' => 'hello']),
        automationToolContext($run, $agent),
    );

    expect($decision->pausesRun())->toBeTrue()
        ->and($decision->outcome)->toBe(PolicyOutcome::RequireApproval)
        ->and($decision->reason)->toContain('autonomous');
});

it('lets a read-only tool through at every level', function (): void {
    // `observe_only` forbids CHANGING things, not knowing them. An agent that
    // could not read would have nothing to report.
    app(ToolRegistry::class)->register(LookupOrderTool::class);

    $agent = AgentFactory::database([
        'slug' => 'reader',
        'tool_policy' => ['allow' => ['lookup_order']],
    ]);

    foreach (AutonomyLevel::cases() as $level) {
        $run = automationRunAtLevel($agent, $level);

        expect(app(ToolGatekeeper::class)->evaluate(
            new ToolCall('call-1', 'lookup_order', ['reference' => 'ORD-1']),
            automationToolContext($run, $agent),
        )->layer)->not->toBe(AuthorizationLayer::Autonomy);
    }
});

// ---------------------------------------------------------------- criterion 16

it('disables itself and notifies an admin when the autonomy budget is exhausted', function (): void {
    Notification::fake();

    config()->set('pandora.automation.autonomy.notify', ['ops@example.test']);

    $automation = AutomationFactory::due([
        'autonomy_budget_runs' => 2,
        'autonomy_budget_window_seconds' => 3600,
        'concurrency_policy' => 'allow',
    ]);

    $this->dispatcher->dispatch($automation, Carbon::parse('2026-07-01 09:00:00'));
    $this->dispatcher->dispatch($automation->refresh(), Carbon::parse('2026-07-01 10:00:00'));

    // The third occurrence is over budget.
    $third = $this->dispatcher->dispatch($automation->refresh(), Carbon::parse('2026-07-01 11:00:00'));

    expect($third->status)->toBe(OccurrenceStatus::Refused)
        ->and($third->reason)->toBe('autonomy_budget')
        // ADR-0009: exhausting the budget does not skip the occurrence, it
        // stops the automation. One that merely skipped would keep trying
        // forever and nobody would learn it was broken.
        ->and($automation->refresh()->enabled)->toBeFalse()
        ->and($automation->next_run_at)->toBeNull()
        ->and($automation->disabled_reason)->toContain('autonomy budget')
        ->and(AuditLog::query()->pluck('action')->all())->toContain('automation.budget_exhausted');

    Notification::assertSentOnDemand(AutomationDisabled::class);
});

it('charges the budget only for occurrences that became runs', function (): void {
    // A misconfigured condition must not be able to disable a healthy
    // automation by burning its budget on skips.
    config()->set('pandora.automation.conditions', [
        'never' => static fn (array $arguments): bool => false,
    ]);

    $automation = AutomationFactory::due([
        'autonomy_budget_runs' => 1,
        'condition' => ['name' => 'never'],
    ]);

    foreach (range(1, 5) as $hour) {
        $this->dispatcher->dispatch($automation->refresh(), Carbon::parse("2026-07-01 0{$hour}:00:00"));
    }

    expect($automation->refresh()->enabled)->toBeTrue();
});

it('leaves an automation with no budget alone', function (): void {
    $automation = AutomationFactory::due([
        'autonomy_budget_runs' => null,
        'concurrency_policy' => 'allow',
    ]);

    foreach (range(1, 4) as $hour) {
        $this->dispatcher->dispatch($automation->refresh(), Carbon::parse("2026-07-01 0{$hour}:00:00"));
    }

    expect($automation->refresh()->enabled)->toBeTrue();
});

it('does not fail the occurrence when notifying an admin fails', function (): void {
    // This runs on the failure path of an automation that has already stopped.
    // A mail server being down must not turn a disabled automation into a
    // failed queue job that retries forever.
    config()->set('pandora.automation.autonomy.notify', ['ops@example.test']);
    // A transport that throws the moment anything is sent through it.
    config()->set('mail.mailers.broken', ['transport' => 'failover', 'mailers' => []]);
    config()->set('mail.default', 'broken');

    $automation = AutomationFactory::due(['autonomy_budget_runs' => 1]);

    $this->dispatcher->dispatch($automation, Carbon::parse('2026-07-01 09:00:00'));
    $second = $this->dispatcher->dispatch($automation->refresh(), Carbon::parse('2026-07-01 10:00:00'));

    expect($second->status)->toBe(OccurrenceStatus::Refused)
        ->and($automation->refresh()->enabled)->toBeFalse();
});

// ------------------------------------------------------------------ helpers

function automationRunAtLevel(
    Agent $agent,
    ?AutonomyLevel $level,
): Run {
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

    return $run;
}

function automationToolContext(Run $run, Agent $agent): ToolContext
{
    return new ToolContext(
        run: $run,
        agent: $agent,
        session: new Session(['agent_id' => $agent->getKey()]),
        // A real user, so the denial under test is unmistakably the autonomy
        // layer rather than the system-actor default in Tool::authorize().
        actor: ActorContext::forUser(test()->actingAsUser()),
        toolCallId: 'call-1',
    );
}
