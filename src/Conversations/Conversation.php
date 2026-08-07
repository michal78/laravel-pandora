<?php

declare(strict_types=1);

namespace Pandora\Conversations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Pandora\Agents\Agent;
use Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Messages\Message;
use Pandora\Runs\Run;
use Pandora\Support\Concerns\PandoraModel;

/**
 * A user-facing thread. Persists across many runs.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $agent_id
 * @property string|null $title
 * @property string $channel
 * @property string $status
 * @property bool $pinned
 * @property array<int, string> $tags
 * @property Carbon|null $last_activity_at
 * @property string|null $parent_conversation_id
 * @property string|null $forked_at_message_id
 * @property string|null $provider_override
 * @property string|null $model_override
 * @property string|null $created_by_type
 * @property string|null $created_by_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Conversation extends Model
{
    use BelongsToTenant;
    use PandoraModel;
    use SoftDeletes;

    protected string $pandoraTable = 'conversations';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'agent_id', 'title', 'channel', 'status', 'pinned', 'tags',
        'parent_conversation_id', 'forked_at_message_id',
        'provider_override', 'model_override',
        'created_by_type', 'created_by_id', 'last_activity_at', 'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pinned' => 'boolean',
            'tags' => 'array',
            'metadata' => 'array',
            'last_activity_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id')->orderBy('sequence');
    }

    /** @return HasMany<Run, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class, 'conversation_id');
    }

    /** @return HasMany<Session, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class, 'conversation_id');
    }

    /** @return HasMany<ConversationParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class, 'conversation_id');
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    /**
     * The next message sequence.
     *
     * Derived from the database rather than a counter column so a concurrent
     * writer cannot silently reuse a number; the unique index on
     * (conversation_id, sequence) is the actual guarantee.
     */
    public function nextSequence(): int
    {
        return (int) $this->messages()->max('sequence') + 1;
    }
}
