<?php

declare(strict_types=1);

namespace Pandora\Channels;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Pandora\Core\Actor\ActorContext;
use Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Support\Concerns\PandoraModel;

/**
 * A participant in a remote system. NOT a user, and never becomes one.
 *
 * Everything in this row except `linked_user_id` is a fact about somebody
 * else's software: an ID Slack minted, a display name the participant chose,
 * whatever the payload carried. None of it is ever consulted to find a host
 * user. The single path from here to an actor is `linked_user_id`, and it is
 * null until a human completes both halves of the linking flow -- a code
 * issued into the channel, redeemed inside an authenticated host session
 * (ADR-0015).
 *
 * The tempting shortcut is matching `metadata['email']` against the host
 * `users` table. That address is asserted by whoever administers a workspace
 * anyone can create, so the shortcut is an authentication bypass wearing a
 * join. There is deliberately no method on this class that could be mistaken
 * for one.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $account_id
 * @property string $external_id
 * @property string|null $display_name
 * @property array<string, mixed>|null $metadata
 * @property string|null $linked_user_type
 * @property string|null $linked_user_id
 * @property Carbon|null $linked_at
 * @property int $link_epoch
 * @property string|null $conversation_id
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ChannelIdentity extends Model
{
    use BelongsToTenant;
    use PandoraModel;

    protected string $pandoraTable = 'channel_identities';

    /** @var array<string, mixed> */
    protected $attributes = [
        'link_epoch' => 0,
    ];

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'account_id', 'external_id', 'display_name', 'metadata',
        'linked_user_type', 'linked_user_id', 'linked_at', 'link_epoch',
        'conversation_id', 'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'linked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'link_epoch' => 'integer',
        ];
    }

    /** @return BelongsTo<ChannelAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class, 'account_id');
    }

    public function isLinked(): bool
    {
        return $this->linked_user_id !== null;
    }

    /**
     * The participant component of the session isolation key.
     *
     * The link epoch is in here, which is the whole reason re-linking is safe.
     * A Slack handle reassigned to a new employee produces a different key, so
     * the new holder starts with no history rather than inheriting the last
     * one's -- a disclosure with no attacker in it, and the kind that is
     * discovered a year later.
     */
    public function participantKey(): string
    {
        return $this->getKey().'#'.$this->link_epoch;
    }

    /**
     * The actor this identity acts as, or null.
     *
     * Null is the answer for an unlinked identity, and the caller's job is to
     * refuse rather than to substitute anything. There is no guest actor: a
     * session is history, cost and context, and handing one to a stranger is
     * the failure this whole module is arranged to avoid.
     */
    public function actor(): ?ActorContext
    {
        $user = $this->linkedUser();

        return $user === null ? null : ActorContext::forUser($user);
    }

    /**
     * Resolve the linked host user, or null if the link is gone.
     *
     * A user deleted after linking leaves a row pointing at nothing. That
     * resolves to null and the next message is refused, which is the correct
     * direction: a deprovisioned account should lose access, not keep it
     * because a channel row outlived it.
     */
    public function linkedUser(): ?Authorizable
    {
        if ($this->linked_user_type === null || $this->linked_user_id === null) {
            return null;
        }

        if (! class_exists($this->linked_user_type)) {
            return null;
        }

        if (! is_subclass_of($this->linked_user_type, Model::class)) {
            return null;
        }

        /** @var Model $model */
        $model = new $this->linked_user_type;

        $found = $model->newQuery()->find($this->linked_user_id);

        return $found instanceof Authorizable ? $found : null;
    }
}
