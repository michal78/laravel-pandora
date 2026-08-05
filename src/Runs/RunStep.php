<?php

declare(strict_types=1);

namespace Pandora\Pandora\Runs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Pandora\Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Pandora\Runs\Enums\RunStepStatus;
use Pandora\Pandora\Runs\Enums\RunStepType;
use Pandora\Pandora\Support\Concerns\Immutable;
use Pandora\Pandora\Support\Concerns\PandoraModel;

/**
 * One ordered, timed, typed entry in a run's trace. Append-only.
 *
 * `payload` is redacted where it is constructed. `raw_meta` holds unmapped
 * vendor fields for debugging and is visible only to holders of
 * `pandora.runs.trace.view`.
 *
 * @property string $id
 * @property string $run_id
 * @property int $sequence
 * @property RunStepType $type
 * @property RunStepStatus $status
 * @property string|null $label
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $raw_meta
 * @property string|null $tenant_id
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int|null $cost_minor
 * @property int|null $duration_ms
 * @property string|null $error_class
 * @property string|null $error_message
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 */
final class RunStep extends Model
{
    use BelongsToTenant;
    use Immutable;
    use PandoraModel;

    protected string $pandoraTable = 'run_steps';

    /** Steps are never updated, so there is no `updated_at` to maintain. */
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'run_id', 'sequence', 'type', 'status', 'label',
        'payload', 'raw_meta', 'input_tokens', 'output_tokens', 'cost_minor',
        'started_at', 'finished_at', 'duration_ms', 'error_class', 'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => RunStepType::class,
            'status' => RunStepStatus::class,
            'payload' => 'array',
            'raw_meta' => 'array',
            'sequence' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Run, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'run_id');
    }
}
