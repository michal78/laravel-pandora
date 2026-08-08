<?php

declare(strict_types=1);

namespace Pandora\Channels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Support\Concerns\PandoraModel;

/**
 * A single-use, short-lived proof that somebody controls a channel account.
 *
 * Stored hashed for the same reason a password reset token is: it is a
 * credential that grants an identity, so read access to this table must not be
 * the same thing as the ability to become somebody. The plaintext exists once,
 * in the reply sent back into the channel, and nowhere else.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $identity_id
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property string|null $redeemed_by_type
 * @property string|null $redeemed_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ChannelLinkCode extends Model
{
    use BelongsToTenant;
    use PandoraModel;

    protected string $pandoraTable = 'channel_link_codes';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'identity_id', 'code_hash', 'expires_at', 'consumed_at',
        'redeemed_by_type', 'redeemed_by_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /**
     * Hidden as well as hashed. A hash is not a secret, but it is the input to
     * an offline search over a small code space, and there is no reason for it
     * to appear in an API response or a broadcast.
     *
     * @var list<string>
     */
    protected $hidden = ['code_hash'];

    /** @return BelongsTo<ChannelIdentity, $this> */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(ChannelIdentity::class, 'identity_id');
    }

    public function isRedeemable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
