<?php

declare(strict_types=1);

namespace Pandora\Channels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Pandora\Channels\Enums\DeliveryDirection;
use Pandora\Channels\Enums\DeliveryStatus;
use Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Support\Concerns\PandoraModel;

/**
 * One message that crossed the boundary, in either direction, and what became
 * of it.
 *
 * Two jobs. Inbound, the unique index on
 * `(account, direction, external_message_id)` is the idempotency guard -- Slack
 * retries, and a retry must produce one run rather than two. Outbound, it is
 * the record that makes an undeliverable reply visible: a failure here is a
 * state an operator can see, which is what makes it acceptable never to
 * re-route the message somewhere it might actually arrive.
 *
 * A refused inbound message is recorded too. An unlinked stranger messaging an
 * agent is exactly the event worth being able to count later.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $account_id
 * @property string|null $identity_id
 * @property string|null $run_id
 * @property DeliveryDirection $direction
 * @property string|null $external_message_id
 * @property DeliveryStatus $status
 * @property string|null $error
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ChannelDelivery extends Model
{
    use BelongsToTenant;
    use PandoraModel;

    protected string $pandoraTable = 'channel_deliveries';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'account_id', 'identity_id', 'run_id', 'direction',
        'external_message_id', 'status', 'error', 'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => DeliveryDirection::class,
            'status' => DeliveryStatus::class,
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<ChannelAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class, 'account_id');
    }

    /** @return BelongsTo<ChannelIdentity, $this> */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(ChannelIdentity::class, 'identity_id');
    }
}
