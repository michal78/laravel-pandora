<?php

declare(strict_types=1);

namespace Pandora\Tools;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Runs\Run;
use Pandora\Support\Concerns\PandoraModel;
use Pandora\Tools\Enums\RiskLevel;
use Pandora\Tools\Enums\ToolExecutionStatus;

/**
 * The record of one tool call: what was asked for, what was decided, what
 * happened.
 *
 * The row exists from the moment a call is decided, before anything runs, so a
 * paused or denied call is as visible as a successful one. That is what lets a
 * run wait three days for an approval and still be explicable afterwards.
 *
 * `arguments` are the real ones, because they must survive a pause and be
 * executed later. `sanitized_arguments` are the redacted copy, and the only
 * ones any UI, trace, broadcast or audit entry is permitted to show.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $run_id
 * @property string|null $run_step_id
 * @property string $tool_name
 * @property string $tool_version
 * @property string $tool_call_id
 * @property array<string, mixed>|null $arguments
 * @property array<string, mixed>|null $sanitized_arguments
 * @property bool $arguments_modified
 * @property array<int, array{field: string, from: mixed, to: mixed}>|null $argument_diff
 * @property array<string, mixed>|null $result
 * @property array<string, mixed>|null $sanitized_result
 * @property ToolExecutionStatus $status
 * @property RiskLevel $risk_level
 * @property string|null $decided_by
 * @property string|null $decision_reason
 * @property bool $required_approval
 * @property string|null $approval_id
 * @property string|null $approver_type
 * @property string|null $approver_id
 * @property string $idempotency_key
 * @property int $attempt
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property string|null $error_class
 * @property string|null $error_message
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ToolExecution extends Model
{
    use BelongsToTenant;
    use PandoraModel;

    protected string $pandoraTable = 'tool_executions';

    /**
     * Explicit fillable -- never `$guarded = []`. This row decides what an LLM
     * is about to do to the application.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id', 'run_id', 'run_step_id', 'tool_name', 'tool_version', 'tool_call_id',
        'arguments', 'sanitized_arguments', 'arguments_modified', 'argument_diff',
        'result', 'sanitized_result', 'status', 'risk_level', 'decided_by', 'decision_reason',
        'required_approval', 'approval_id', 'approver_type', 'approver_id',
        'idempotency_key', 'attempt', 'started_at', 'finished_at', 'duration_ms',
        'error_class', 'error_message', 'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ToolExecutionStatus::class,
            'risk_level' => RiskLevel::class,
            'arguments' => 'array',
            'sanitized_arguments' => 'array',
            'argument_diff' => 'array',
            'result' => 'array',
            'sanitized_result' => 'array',
            'metadata' => 'array',
            'arguments_modified' => 'boolean',
            'required_approval' => 'boolean',
            'attempt' => 'integer',
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

    /** @param Builder<self> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', array_map(
            static fn (ToolExecutionStatus $status): string => $status->value,
            array_filter(
                ToolExecutionStatus::cases(),
                static fn (ToolExecutionStatus $status): bool => $status->isOpen(),
            ),
        ));
    }

    /**
     * A stable key for (run, tool, arguments, attempt).
     *
     * Arguments are canonicalised by sorting keys recursively, so two calls
     * that differ only in the order the model happened to emit them are
     * recognised as the same call -- which is the whole point.
     *
     * @param array<string, mixed> $arguments
     */
    public static function idempotencyKey(
        string $runId,
        string $toolName,
        array $arguments,
        int $attempt = 1,
    ): string {
        return hash('sha256', implode('|', [
            $runId,
            $toolName,
            (string) json_encode(self::canonicalize($arguments)),
            (string) $attempt,
        ]));
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function canonicalize(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalize($item);
            }
        }

        return $value;
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * What the model is shown as this call's result.
     */
    public function modelContent(): string
    {
        /** @var string $content */
        $content = $this->result['content'] ?? $this->error_message ?? 'No result.';

        return $content;
    }
}
