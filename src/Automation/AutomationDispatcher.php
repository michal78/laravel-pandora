<?php

declare(strict_types=1);

namespace Pandora\Automation;

use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Pandora\Agents\Agent;
use Pandora\Agents\AgentRunner;
use Pandora\Audit\AuditLogger;
use Pandora\Automation\Enums\ConcurrencyPolicy;
use Pandora\Automation\Enums\OccurrenceStatus;
use Pandora\Core\Tenancy\TenantManager;
use Pandora\Exceptions\AutomationRefused;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Run;
use Pandora\Runs\RunCanceller;
use Pandora\Support\Concerns\DetectsUniqueViolations;

/**
 * Turns one occurrence of an automation into at most one run.
 *
 * The sequence is fixed and each step is cheaper than the next:
 *
 *   claim  →  agent  →  condition  →  concurrency  →  autonomy  →  run
 *
 * **The claim is an INSERT, and it is the whole concurrency story.** The
 * occurrence's idempotency key is derived deterministically from the
 * automation and the occurrence timestamp, and carries a unique index. Two
 * schedulers that both notice the 09:00 occurrence compute the same key, both
 * try to insert, and exactly one succeeds -- decided by the database, before
 * either has evaluated a condition or spent a token.
 *
 * The alternative everybody writes first is `if ($automation->last_run_at <
 * $due)`, which is a check-then-act race whose window is a database round
 * trip. It fails exactly under the load that made somebody run two schedulers.
 *
 * Every refusal after a successful claim UPDATES the claimed row rather than
 * deleting it, so the history distinguishes "never fired" from "fired and
 * declined". A silence looks identical to a scheduler that died last Tuesday.
 */
final class AutomationDispatcher
{
    use DetectsUniqueViolations;

    public function __construct(
        private readonly AgentRunner $agents,
        private readonly ConditionRegistry $conditions,
        private readonly AutonomyBudget $autonomy,
        private readonly AuditLogger $audit,
        private readonly TenantManager $tenants,
        private readonly RunCanceller $canceller,
    ) {}

    /**
     * Dispatch one occurrence.
     *
     * Returns the occurrence row, or null when this occurrence was already
     * claimed by somebody else -- which is a success, not an error: the run
     * exists, it just is not ours.
     *
     * @param array<string, mixed> $payload extra context, e.g. a webhook body
     */
    public function dispatch(
        Automation $automation,
        CarbonInterface $occurrence,
        array $payload = [],
        ?string $idempotencyKey = null,
        bool $synchronous = false,
    ): ?AutomationRun {
        $key = $idempotencyKey ?? AutomationRun::keyFor((string) $automation->getKey(), $occurrence);

        $claim = $this->claim($automation, $occurrence, $key);

        if ($claim === null) {
            return null;
        }

        try {
            $agent = $this->agentFor($automation);

            $this->assertConditionHolds($automation);
            $this->assertNotAlreadyRunning($automation);
            $this->autonomy->assert($automation);

            $run = $this->createRun($automation, $agent, $key, $payload, $synchronous);
        } catch (AutomationRefused $e) {
            return $this->refuse($automation, $claim, $e);
        }

        $claim->forceFill([
            'status' => OccurrenceStatus::Dispatched,
            'run_id' => $run->getKey(),
        ])->save();

        $automation->forceFill([
            'last_run_at' => now(),
            'last_run_id' => $run->getKey(),
            'consecutive_failures' => 0,
        ])->save();

        $this->audit->record(
            action: 'automation.fired',
            targetType: 'automation',
            targetId: $automation->id,
            runId: (string) $run->getKey(),
            metadata: [
                'slug' => $automation->slug,
                'trigger' => $automation->trigger_type->value,
                'scheduled_for' => $occurrence->toIso8601String(),
                'autonomy_level' => $run->autonomy_level?->value,
            ],
        );

        return $claim;
    }

    /**
     * Insert the claim, or discover somebody else has it.
     *
     * A unique-constraint violation is the expected, healthy outcome under
     * concurrency, so it is caught here rather than allowed to fail a job.
     * Any other query error is a real problem and is left to propagate.
     */
    private function claim(Automation $automation, CarbonInterface $occurrence, string $key): ?AutomationRun
    {
        try {
            /** @var AutomationRun $claim */
            $claim = AutomationRun::query()->create([
                'tenant_id' => $automation->tenant_id ?? $this->tenants->currentId(),
                'automation_id' => $automation->getKey(),
                'scheduled_for' => $occurrence,
                'status' => OccurrenceStatus::Claimed->value,
                'idempotency_key' => $key,
            ]);

            return $claim;
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return null;
            }

            throw $e;
        }
    }

    private function agentFor(Automation $automation): Agent
    {
        /** @var Agent|null $agent */
        $agent = Agent::query()->find($automation->agent_id);

        if ($agent === null) {
            throw AutomationRefused::agentMissing($automation->slug);
        }

        if (! $agent->enabled) {
            throw AutomationRefused::agentDisabled($automation->slug, $agent->slug);
        }

        return $agent;
    }

    private function assertConditionHolds(Automation $automation): void
    {
        if ($this->conditions->evaluate($automation)) {
            return;
        }

        $name = $automation->condition['name'] ?? 'condition';

        throw AutomationRefused::conditionFalse($automation->slug, is_string($name) ? $name : 'condition');
    }

    /**
     * The concurrency policy, applied to runs this automation actually
     * started -- not to the agent's runs generally. An automation that shares
     * an agent with the chat page must not be blocked by somebody typing.
     */
    private function assertNotAlreadyRunning(Automation $automation): void
    {
        if ($automation->concurrency_policy === ConcurrencyPolicy::Allow) {
            return;
        }

        $live = Run::query()
            ->where('automation_id', $automation->getKey())
            ->whereIn('state', RunState::liveStates())
            ->get();

        if ($live->isEmpty()) {
            return;
        }

        if ($automation->concurrency_policy === ConcurrencyPolicy::Skip) {
            throw AutomationRefused::alreadyRunning($automation->slug);
        }

        // CancelPrevious: only the latest occurrence matters. Cancellation is
        // requested, not forced -- in-flight tool calls still finish, because
        // killing one mid-write is worse than letting it complete.
        foreach ($live as $run) {
            $this->canceller->cancel($run, 'Superseded by a later occurrence of this automation.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createRun(
        Automation $automation,
        Agent $agent,
        string $key,
        array $payload,
        bool $synchronous,
    ): Run {
        $pending = $this->agents->agent($agent)
            // A system actor carries no Authorizable, so any tool whose
            // authorization depends on a user is denied rather than quietly
            // allowed. An automation is not a person.
            ->asSystem('automation:'.$automation->slug)
            ->triggeredBy($automation->runTrigger())
            ->idempotencyKey($key)
            ->withContext(array_merge(
                $automation->context ?? [],
                $payload === [] ? [] : ['payload' => $payload],
                ['automation' => ['slug' => $automation->slug, 'name' => $automation->name]],
            ));

        $run = $synchronous
            ? $pending->run($automation->instruction())
            : $pending->dispatch($automation->instruction());

        // Stamped after creation rather than threaded through PendingAgentRun:
        // these two columns exist for THIS phase, and adding two automation
        // concepts to the general run builder would put them in front of every
        // caller that has nothing to do with automation.
        $run->forceFill([
            'automation_id' => $automation->getKey(),
            'autonomy_level' => $automation->effectiveAutonomy($agent)->value,
        ])->save();

        return $run;
    }

    /**
     * Record why this occurrence produced nothing.
     *
     * `condition` and `unknown_condition` are `skipped`; a policy saying no is
     * `refused`. The distinction matters to whoever is reading the history at
     * 3am: a skip is the automation working, a refusal is the automation
     * being stopped.
     */
    private function refuse(Automation $automation, AutomationRun $claim, AutomationRefused $e): AutomationRun
    {
        $skipped = in_array($e->reason, ['condition', 'unknown_condition'], true);

        $claim->forceFill([
            'status' => $skipped ? OccurrenceStatus::Skipped : OccurrenceStatus::Refused,
            'reason' => $e->reason,
            'error' => $e->getMessage(),
        ])->save();

        $this->audit->record(
            action: 'automation.refused',
            targetType: 'automation',
            targetId: $automation->id,
            severity: $skipped ? 'info' : 'warning',
            metadata: [
                'slug' => $automation->slug,
                'reason' => $e->reason,
                'message' => $e->getMessage(),
            ],
        );

        return $claim;
    }
}
