<?php

declare(strict_types=1);

namespace Pandora\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Support\Concerns\Immutable;
use Pandora\Support\Concerns\PandoraModel;

/**
 * An append-only security record.
 *
 * Conceptually separate from application logs (operational) and from run steps
 * (execution trace). Records what was ATTEMPTED, whether or not it succeeded --
 * a denied action is often the more interesting row.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $correlation_id
 * @property string $action
 * @property string $severity
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string|null $target_type
 * @property string|null $target_id
 * @property string|null $run_id
 * @property string|null $ip
 * @property string|null $user_agent
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 */
final class AuditLog extends Model
{
    use BelongsToTenant;
    use Immutable;
    use PandoraModel;

    protected string $pandoraTable = 'audit_logs';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'correlation_id', 'actor_type', 'actor_id', 'action',
        'target_type', 'target_id', 'run_id', 'severity', 'ip', 'user_agent', 'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
