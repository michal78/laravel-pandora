<?php

declare(strict_types=1);

namespace Pandora\Delegation;

use Illuminate\Database\ConnectionInterface;
use Pandora\Agents\Agent;
use Pandora\Audit\AuditLogger;
use Pandora\Conversations\Session;
use Pandora\Core\Actor\ActorContext;
use Pandora\Jobs\StartAgentRun;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Enums\RunStepStatus;
use Pandora\Runs\Enums\RunStepType;
use Pandora\Runs\Enums\TriggerType;
use Pandora\Runs\Run;
use Pandora\Runs\RunFactory;
use Pandora\Runs\RunStateMachine;
use Pandora\Runs\RunStepRecorder;
use Pandora\Tools\Enums\ToolExecutionStatus;
use Pandora\Tools\ToolExecution;

/**
 * Creates and starts a child run, once the guard has said it may exist.
 *
 * Deliberately dumb about permission: every question of "may this happen" was
 * answered by `DelegationGuard`, and this class refuses to re-ask any of it. A
 * second place that decides whether a delegation is allowed is a second place
 * to get it wrong, and the two would not disagree loudly -- they would disagree
 * on one edge, once, in production.
 */
final class Delegator
{
    public function __construct(
        private readonly RunFactory $runs,
        private readonly RunStateMachine $states,
        private readonly RunStepRecorder $steps,
        private readonly AuditLogger $audit,
        private readonly ConnectionInterface $connection,
    ) {}

    /**
     * Start a child run answering `$execution` on the parent.
     *
     * The actor travels down unchanged. A delegated run is attributable to the
     * PERSON who set the tree in motion, not to the agent that happened to
     * forward the request -- an agent is never an actor, and a trace that named
     * one would lose the only identity the tool authorization layer checks
     * against. Two hops from a user request is still that user's request.
     */
    public function start(
        Run $parent,
        Agent $parentAgent,
        Session $session,
        DelegationDecision $decision,
        ToolExecution $execution,
        string $instruction,
        ?ActorContext $actor,
    ): Run {
        /** @var Agent $target */
        $target = $decision->target;

        $child = $this->connection->transaction(function () use (
            $parent, $session, $decision, $execution, $instruction, $actor, $target
        ): Run {
            $child = $this->runs->create(
                agent: $target,
                session: $session,
                // Deliberately NOT the parent's conversation. A child's
                // messages are its own working notes; threading them into the
                // conversation would show a user a second agent talking to
                // itself, and would feed the child's raw output back into the
                // parent's context by a route that skips the tool result --
                // which is the one place it gets treated as untrusted.
                conversation: null,
                actor: $actor,
                trigger: TriggerType::Delegation,
                input: $instruction,
                parent: $parent,
            );

            $child->forceFill([
                // The intersection, frozen. See AbilityIntersection.
                'effective_tools' => $decision->effectiveTools,
                'delegated_tool_execution_id' => (string) $execution->getKey(),
            ])->save();

            // Marked as outstanding BEFORE the child is dispatched, and the
            // ordering is load-bearing rather than tidy. A child can reach a
            // terminal state at any moment after it starts -- immediately, on
            // a synchronous queue -- and `DelegationCompleter` closes this row
            // when it does. Marking afterwards would race the completer and
            // sometimes reopen a call the child had already answered, leaving
            // a finished run waiting on a tool that will never report again.
            $execution->forceFill([
                'status' => ToolExecutionStatus::Running->value,
                'started_at' => $execution->started_at ?? now(),
                'decision_reason' => sprintf('Waiting for %s to answer.', $target->name),
            ])->save();

            return $child;
        });

        $this->steps->record(
            $parent,
            RunStepType::Delegation,
            RunStepStatus::Started,
            $decision->toTrace() + [
                'child_run_id' => (string) $child->getKey(),
                'delegation_depth' => $child->delegation_depth,
            ],
            label: 'Delegated to '.$target->name,
        );

        $this->audit->record(
            action: 'delegation.started',
            targetType: Run::class,
            targetId: (string) $child->getKey(),
            runId: (string) $parent->getKey(),
            metadata: $decision->toTrace() + [
                'parent_run_id' => (string) $parent->getKey(),
                'parent_agent' => $parentAgent->slug,
                'delegation_depth' => $child->delegation_depth,
            ],
        );

        $this->states->transition($child, RunState::Queued);

        StartAgentRun::dispatch(
            (string) $child->getKey(),
            $child->tenant_id,
            $actor?->type,
            $actor?->id,
        );

        return $child;
    }

    /**
     * Record a refusal against the parent, which keeps running.
     *
     * A denied delegation is a tool error, never a run-ending one. Failing the
     * whole run would make a bounded refusal look like an outage to whoever is
     * watching, and would teach operators that the way to stop the alerts is to
     * raise the limit.
     */
    public function refuse(Run $parent, Agent $parentAgent, DelegationDecision $decision): void
    {
        /** @var DelegationRefusal $refusal */
        $refusal = $decision->refusal;

        $this->steps->record(
            $parent,
            RunStepType::Delegation,
            RunStepStatus::Failed,
            $decision->toTrace(),
            label: 'Delegation refused',
        );

        $this->audit->record(
            action: $refusal->auditAction(),
            targetType: Run::class,
            targetId: (string) $parent->getKey(),
            runId: (string) $parent->getKey(),
            severity: $refusal->severity(),
            metadata: $decision->toTrace() + [
                'parent_agent' => $parentAgent->slug,
                'delegation_depth' => $parent->delegation_depth,
            ],
        );
    }
}
