<?php

declare(strict_types=1);

namespace Pandora\Usage;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Support\Concerns\Immutable;
use Pandora\Support\Concerns\PandoraModel;

/**
 * One model call, measured.
 *
 * Append-only, like run steps and audit entries: a measurement that can be
 * edited afterwards is not evidence of anything.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $run_id
 * @property string|null $agent_id
 * @property string|null $conversation_id
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string $provider_key
 * @property string $model_key
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cached_input_tokens
 * @property int $cached_output_tokens
 * @property int $reasoning_tokens
 * @property int $total_tokens
 * @property int $requests
 * @property int $duration_ms
 * @property int|null $cost_micro
 * @property string $currency
 * @property string|null $pricing_source
 * @property Carbon|null $pricing_date
 * @property bool $pricing_stale
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 */
final class UsageRecord extends Model
{
    use BelongsToTenant;
    use Immutable;
    use PandoraModel;

    protected string $pandoraTable = 'usage_records';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'run_id', 'agent_id', 'conversation_id', 'actor_type', 'actor_id',
        'provider_key', 'model_key',
        'input_tokens', 'output_tokens', 'cached_input_tokens', 'cached_output_tokens',
        'reasoning_tokens', 'total_tokens', 'requests', 'duration_ms',
        'cost_micro', 'currency', 'pricing_source', 'pricing_date', 'pricing_stale',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cached_input_tokens' => 'integer',
            'cached_output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'total_tokens' => 'integer',
            'requests' => 'integer',
            'duration_ms' => 'integer',
            'cost_micro' => 'integer',
            'pricing_date' => 'date',
            'pricing_stale' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    public function reference(): string
    {
        return $this->provider_key.'/'.$this->model_key;
    }

    /**
     * The cost in minor units, or null when the model is unpriced.
     */
    public function costMinor(): ?int
    {
        return $this->cost_micro === null ? null : (int) round($this->cost_micro / 10_000);
    }
}
