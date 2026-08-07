<?php

declare(strict_types=1);

namespace Pandora\Automation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Runs\Run;
use Pandora\Support\Concerns\PandoraModel;

/**
 * One inbound webhook delivery, accepted or rejected.
 *
 * The `signature` column is the replay nonce and carries a unique index with
 * the automation. Timestamp tolerance alone is not a replay defence: the
 * window has to be generous enough to survive clock skew, and inside it the
 * same request can be sent as many times as the attacker likes. Remembering
 * the signature is the only defence that holds behind a load balancer, where
 * no single process sees every delivery.
 *
 * Rejections are stored too. A stream of rejected deliveries is the earliest
 * sign that a secret was rotated on one side only.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $automation_id
 * @property string|null $run_id
 * @property string $signature
 * @property string $status
 * @property string|null $reason
 * @property int $replay_count
 * @property Carbon|null $last_replayed_at
 * @property string|null $source_ip
 * @property int $payload_bytes
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class WebhookDelivery extends Model
{
    use BelongsToTenant;
    use PandoraModel;

    protected string $pandoraTable = 'webhook_deliveries';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'automation_id', 'run_id', 'signature', 'status',
        'reason', 'source_ip', 'payload_bytes', 'payload',
        'replay_count', 'last_replayed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'payload_bytes' => 'integer',
            'replay_count' => 'integer',
            'last_replayed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Automation, $this> */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class, 'automation_id');
    }

    /** @return BelongsTo<Run, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'run_id');
    }
}
