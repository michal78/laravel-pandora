<?php

declare(strict_types=1);

namespace Pandora\Channels;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Pandora\Agents\Agent;
use Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Support\Concerns\PandoraModel;

/**
 * One configured connection to a remote workspace on one channel.
 *
 * This row is where tenancy is decided. Every identity, message and run beneath
 * it inherits `tenant_id` from here, written by an operator, and nothing in an
 * inbound payload can select or change it (ADR-0015). A user with handles in
 * two workspaces in two tenants has two identities and two isolation keys,
 * which is the correct answer rather than a coincidence.
 *
 * It holds no secret: `credential_key` names an entry in the encrypted
 * credential store, the same arrangement `McpServer` uses.
 *
 * `enabled` defaults to FALSE. Installing an extension registers an adapter; it
 * does not connect anything to anything (ADR-0016).
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $channel
 * @property string $name
 * @property string $slug
 * @property string $external_id
 * @property string|null $agent_id
 * @property string|null $credential_key
 * @property bool $enabled
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ChannelAccount extends Model
{
    use BelongsToTenant;
    use PandoraModel;

    protected string $pandoraTable = 'channel_accounts';

    /** @var array<string, mixed> */
    protected $attributes = [
        'enabled' => false,
    ];

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'channel', 'name', 'slug', 'external_id', 'agent_id',
        'credential_key', 'enabled', 'settings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'settings' => 'array',
        ];
    }

    /**
     * Overridden from `BelongsToTenant` only to carry the precise builder type.
     *
     * The one deliberate cross-tenant read in this module is the inbound
     * account lookup: a webhook arrives with no tenant resolved, and this row
     * is what decides which one it is.
     *
     * @return Builder<self>
     */
    public static function acrossAllTenants(): Builder
    {
        return self::query()->withoutGlobalScope('pandora_tenant');
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    /** @return HasMany<ChannelIdentity, $this> */
    public function identities(): HasMany
    {
        return $this->hasMany(ChannelIdentity::class, 'account_id');
    }

    /** @return HasMany<ChannelDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(ChannelDelivery::class, 'account_id');
    }

    /**
     * Ready to carry a conversation: switched on, and pointed at an agent.
     *
     * An account with no agent is not a broken account -- it is one an operator
     * has registered and not yet aimed. It accepts nothing until they do,
     * because a message with nowhere to go is better refused than queued
     * against a default nobody chose.
     */
    public function isUsable(): bool
    {
        return $this->enabled && $this->agent_id !== null;
    }
}
