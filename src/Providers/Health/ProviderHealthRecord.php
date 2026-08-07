<?php

declare(strict_types=1);

namespace Pandora\Providers\Health;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pandora\Providers\Data\ProviderHealth;
use Pandora\Support\Concerns\PandoraModel;

/**
 * The last thing we learned about a provider.
 *
 * Deployment-wide, not per-tenant: whether api.openai.com is reachable is not
 * a fact that differs by customer.
 *
 * @property string $id
 * @property string $provider_key
 * @property string $status
 * @property int|null $latency_ms
 * @property int $consecutive_failures
 * @property int $consecutive_successes
 * @property string|null $last_error
 * @property Carbon|null $checked_at
 * @property Carbon|null $degraded_since
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ProviderHealthRecord extends Model
{
    use PandoraModel;

    protected string $pandoraTable = 'provider_health';

    /** @var list<string> */
    protected $fillable = [
        'provider_key', 'status', 'latency_ms', 'consecutive_failures',
        'consecutive_successes', 'last_error', 'checked_at', 'degraded_since',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latency_ms' => 'integer',
            'consecutive_failures' => 'integer',
            'consecutive_successes' => 'integer',
            'checked_at' => 'datetime',
            'degraded_since' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->status !== 'degraded' && $this->status !== 'down';
    }

    public function toHealth(): ProviderHealth
    {
        return new ProviderHealth(
            status: $this->status,
            latencyMs: $this->latency_ms,
            message: $this->last_error,
            checkedAt: $this->checked_at?->toDateTimeImmutable(),
        );
    }
}
