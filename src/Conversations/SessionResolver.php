<?php

declare(strict_types=1);

namespace Pandora\Pandora\Conversations;

use Illuminate\Database\ConnectionInterface;
use Pandora\Pandora\Agents\Agent;
use Pandora\Pandora\Core\Actor\ActorContext;
use Pandora\Pandora\Core\Tenancy\TenantManager;

/**
 * Resolves the session -- the isolation boundary -- for an identity tuple.
 *
 * `firstOrCreate` on the deterministic isolation key means the same tuple
 * always resolves to the same session, and a different tuple never can. The
 * unique index makes a collision a database error rather than a silent context
 * leak, which is the failure mode that actually matters here.
 */
final class SessionResolver
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly TenantManager $tenants,
    ) {}

    public function resolve(
        Agent $agent,
        ?ActorContext $actor,
        ?Conversation $conversation = null,
        string $channel = 'web',
        ?string $participantId = null,
        string $origin = 'web',
    ): Session {
        $tenantId = $this->tenants->currentId();

        $isolationKey = Session::isolationKeyFor(
            tenantId: $tenantId,
            agentId: (string) $agent->getKey(),
            actor: $actor,
            channel: $channel,
            participantId: $participantId,
            // A conversation participates in the key so two conversations with
            // the same agent and actor do not share a context boundary.
            origin: $origin.':'.($conversation?->getKey() ?? 'none'),
        );

        return $this->connection->transaction(
            fn (): Session => Session::query()->firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'isolation_key' => $isolationKey,
                ],
                [
                    'conversation_id' => $conversation?->getKey(),
                    'agent_id' => $agent->getKey(),
                    'actor_type' => $actor?->type,
                    'actor_id' => $actor?->id,
                    'channel' => $channel,
                    'channel_participant_id' => $participantId,
                    'origin' => $origin,
                ],
            ),
        );
    }
}
