<?php

declare(strict_types=1);

namespace Pandora\Pandora\Runs;

use Illuminate\Database\ConnectionInterface;
use Pandora\Pandora\Agents\Agent;
use Pandora\Pandora\Conversations\Conversation;
use Pandora\Pandora\Conversations\Session;
use Pandora\Pandora\Core\Actor\ActorContext;
use Pandora\Pandora\Core\Tenancy\TenantManager;
use Pandora\Pandora\Runs\Enums\RunState;
use Pandora\Pandora\Runs\Enums\TriggerType;
use Pandora\Pandora\Support\CorrelationId;

/**
 * Creates runs in `pending`.
 *
 * Deadline and budgets are stamped at creation from the agent's configuration,
 * so a later change to the agent cannot retroactively extend a run that is
 * already in flight.
 */
final class RunFactory
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly TenantManager $tenants,
        private readonly CorrelationId $correlation,
    ) {}

    public function create(
        Agent $agent,
        Session $session,
        ?Conversation $conversation = null,
        ?ActorContext $actor = null,
        TriggerType $trigger = TriggerType::UserMessage,
        ?string $input = null,
        ?string $idempotencyKey = null,
        ?Run $parent = null,
    ): Run {
        return $this->connection->transaction(function () use (
            $agent, $session, $conversation, $actor, $trigger, $input, $idempotencyKey, $parent
        ): Run {
            if ($idempotencyKey !== null) {
                /** @var Run|null $existing */
                $existing = Run::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            /** @var Run $run */
            $run = Run::query()->create([
                'tenant_id' => $this->tenants->currentId(),
                'agent_id' => $agent->getKey(),
                'conversation_id' => $conversation?->getKey(),
                'session_id' => $session->getKey(),
                'parent_run_id' => $parent?->getKey(),
                'delegation_depth' => $parent !== null ? $parent->delegation_depth + 1 : 0,
                'state' => RunState::Pending->value,
                'trigger_type' => $trigger->value,
                'correlation_id' => $parent?->correlation_id ?? $this->correlation->current(),
                'idempotency_key' => $idempotencyKey,
                'actor_type' => $actor?->type,
                'actor_id' => $actor?->id,
                'provider_key' => $conversation?->provider_override ?? $agent->default_provider,
                'model_key' => $conversation?->model_override ?? $agent->default_model,
                'input' => $input,
                'currency' => $agent->currency ?? 'USD',
                // Stamped now so an agent edit cannot extend a live run.
                'deadline_at' => now()->addSeconds($agent->max_duration_seconds),
            ]);

            return $run;
        });
    }
}
