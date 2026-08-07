<?php

declare(strict_types=1);

namespace Pandora\Approvals;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Pandora\Approvals\Enums\ApprovalKind;
use Pandora\Approvals\Enums\ApprovalScope;
use Pandora\Approvals\Enums\ApprovalStatus;
use Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Runs\Run;
use Pandora\Support\Concerns\PandoraModel;
use Pandora\Tools\Enums\RiskLevel;
use Pandora\Tools\ToolExecution;

/**
 * A human decision a run is waiting for.
 *
 * While one of these is pending, NO job is in flight: the run costs nothing,
 * survives deploys, and can wait days. That is the whole reason Pandora's
 * execution model is a durable state machine rather than a daemon.
 *
 * The card shows `summary` and `sanitized_arguments` -- never the raw ones.
 * An approver seeing "Refund £42.00 to order 1234" is making a decision;
 * one seeing "refund_order" is guessing.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $run_id
 * @property string|null $tool_execution_id
 * @property string $tool_name
 * @property string $tool_version
 * @property RiskLevel $risk_level
 * @property string $summary
 * @property array<string, mixed>|null $sanitized_arguments
 * @property array<int, array{field: string, from: mixed, to: mixed}>|null $proposed_modifications
 * @property ApprovalScope $scope
 * @property ApprovalKind $kind
 * @property ApprovalStatus $status
 * @property string|null $requested_by_type
 * @property string|null $requested_by_id
 * @property string|null $resolved_by_type
 * @property string|null $resolved_by_id
 * @property string|null $comment
 * @property Carbon $expires_at
 * @property Carbon|null $resolved_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Approval extends Model
{
    use BelongsToTenant;
    use PandoraModel;

    protected string $pandoraTable = 'approvals';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'run_id', 'tool_execution_id', 'tool_name', 'tool_version',
        'risk_level', 'summary', 'sanitized_arguments', 'proposed_modifications',
        'scope', 'kind', 'status', 'requested_by_type', 'requested_by_id',
        'resolved_by_type', 'resolved_by_id', 'comment', 'expires_at', 'resolved_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => ApprovalScope::class,
            'kind' => ApprovalKind::class,
            'status' => ApprovalStatus::class,
            'risk_level' => RiskLevel::class,
            'sanitized_arguments' => 'array',
            'proposed_modifications' => 'array',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Run, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'run_id');
    }

    /** @return BelongsTo<ToolExecution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(ToolExecution::class, 'tool_execution_id');
    }

    /** @param Builder<self> $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', ApprovalStatus::Pending->value);
    }

    /** @param Builder<self> $query */
    public function scopeOverdue(Builder $query): void
    {
        $query->where('status', ApprovalStatus::Pending->value)
            ->where('expires_at', '<=', now());
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }

    public function hasExpired(): bool
    {
        return $this->isPending() && $this->expires_at->isPast();
    }

    /**
     * Whether an actor may resolve this.
     *
     * A confirmation is answered by the person who triggered the run; an
     * approval is answered by someone holding `pandora.approvals.resolve`.
     * The distinction is the point: nobody approves their own high-risk call.
     */
    public function isConfirmation(): bool
    {
        return $this->kind === ApprovalKind::Confirmation;
    }
}
