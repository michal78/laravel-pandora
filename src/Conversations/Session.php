<?php

declare(strict_types=1);

namespace Pandora\Pandora\Conversations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Pandora\Pandora\Core\Actor\ActorContext;
use Pandora\Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Pandora\Support\Concerns\PandoraModel;

/**
 * A SECURITY BOUNDARY -- not a routing selector.
 *
 * A session binds (tenant, agent, actor, channel, participant, origin).
 * Context may never cross a session boundary, which is what stops one user's
 * private history reaching another user who shares a conversation or a channel
 * inbox. See docs/product/terminology.md.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $conversation_id
 * @property string $agent_id
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string $channel
 * @property string $origin
 * @property string $isolation_key
 * @property string|null $channel_participant_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Session extends Model
{
    use BelongsToTenant;
    use PandoraModel;

    protected string $pandoraTable = 'sessions';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'conversation_id', 'agent_id', 'actor_type', 'actor_id',
        'channel', 'channel_participant_id', 'origin', 'isolation_key',
        'expires_at', 'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    /**
     * Derive the isolation key for an identity tuple.
     *
     * Deterministic, so the same tuple always resolves to the same session;
     * hashed, so no identifier leaks into a column that appears in URLs and
     * logs. The unique index on (tenant_id, isolation_key) makes collisions a
     * database error rather than a silent context leak.
     */
    public static function isolationKeyFor(
        ?string $tenantId,
        string $agentId,
        ?ActorContext $actor,
        string $channel,
        ?string $participantId,
        string $origin,
    ): string {
        return hash('sha256', implode('|', [
            $tenantId ?? '-',
            $agentId,
            $actor?->type ?? '-',
            $actor?->id ?? '-',
            $channel,
            $participantId ?? '-',
            $origin,
        ]));
    }

    /**
     * Whether this session may read data belonging to the given actor.
     *
     * A system session (an automation) has no actor and is readable only by
     * the session it belongs to.
     */
    public function belongsToActor(?ActorContext $actor): bool
    {
        if ($this->actor_id === null) {
            return false;
        }

        return $actor !== null
            && $this->actor_type === $actor->type
            && $this->actor_id === $actor->id;
    }
}
