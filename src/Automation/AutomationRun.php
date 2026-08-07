<?php

declare(strict_types=1);

namespace Pandora\Automation;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Pandora\Automation\Enums\OccurrenceStatus;
use Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Runs\Run;
use Pandora\Support\Concerns\PandoraModel;

/**
 * One occurrence of an automation, and what became of it.
 *
 * This row is the claim. Its unique `(automation_id, idempotency_key)` index
 * is what makes "exactly once" true under two schedulers, a queue retry and a
 * duplicated webhook delivery -- the second insert is refused by the database,
 * before anything expensive has happened.
 *
 * A refused or skipped occurrence is still written. Distinguishing "it never
 * fired" from "it fired and declined" is the difference between debugging a
 * condition and debugging a cron daemon.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $automation_id
 * @property string|null $run_id
 * @property Carbon $scheduled_for
 * @property OccurrenceStatus $status
 * @property string|null $reason
 * @property string $idempotency_key
 * @property string|null $error
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class AutomationRun extends Model
{
    use BelongsToTenant;
    use PandoraModel;

    protected string $pandoraTable = 'automation_runs';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'automation_id', 'run_id', 'scheduled_for', 'status',
        'reason', 'idempotency_key', 'error', 'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OccurrenceStatus::class,
            'scheduled_for' => 'datetime',
            'metadata' => 'array',
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

    /**
     * The deterministic occurrence key.
     *
     * Two schedulers noticing the same due occurrence must compute the same
     * string, so it is derived from the occurrence rather than from the clock,
     * the process or a random value. Second resolution is deliberate: no
     * schedule Pandora supports has two occurrences in the same second, and
     * sub-second precision would let a millisecond of drift mint a second key.
     */
    public static function keyFor(string $automationId, CarbonInterface $occurrence): string
    {
        // `copy()` because `utc()` mutates a mutable Carbon in place, and this
        // method has no business changing its caller's argument.
        return substr(hash('sha256', $automationId.'@'.$occurrence->copy()->utc()->format('Y-m-d\TH:i:s')), 0, 64);
    }
}
