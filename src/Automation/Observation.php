<?php

declare(strict_types=1);

namespace Pandora\Automation;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Pandora\Agents\Agent;
use Pandora\Automation\Enums\ObservationStatus;
use Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Runs\Run;
use Pandora\Support\Concerns\PandoraModel;

/**
 * Work an agent proposed for itself, waiting for a human.
 *
 * This is the goal queue, and it is deliberately inert. An agent may notice
 * that the weekly reconciliation would be worth running on Mondays and say so;
 * it may not put that in the scheduler. Promotion is a human act behind
 * `pandora.automations.manage`, and the automation it produces starts
 * disabled.
 *
 * The parity matrix classes autonomous promotion as Future for the same reason
 * ADR-0009 exists: an agent that can schedule itself has no leash.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $agent_id
 * @property string|null $run_id
 * @property string $title
 * @property string $proposal
 * @property string|null $rationale
 * @property string|null $suggested_cron
 * @property ObservationStatus $status
 * @property string|null $automation_id
 * @property string|null $resolved_by_type
 * @property string|null $resolved_by_id
 * @property Carbon|null $resolved_at
 * @property string|null $comment
 * @property Carbon|null $expires_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Observation extends Model
{
    use BelongsToTenant;
    use PandoraModel;

    protected string $pandoraTable = 'observations';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'agent_id', 'run_id', 'title', 'proposal', 'rationale',
        'suggested_cron', 'status', 'automation_id', 'resolved_by_type',
        'resolved_by_id', 'resolved_at', 'comment', 'expires_at', 'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ObservationStatus::class,
            'resolved_at' => 'datetime',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    /** @return BelongsTo<Run, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'run_id');
    }

    /** @return BelongsTo<Automation, $this> */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class, 'automation_id');
    }

    /** @param Builder<self> $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', ObservationStatus::Pending->value);
    }

    public function isPending(): bool
    {
        return $this->status === ObservationStatus::Pending;
    }
}
