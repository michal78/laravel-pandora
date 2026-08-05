<?php

declare(strict_types=1);

namespace Pandora\Pandora\Automation;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Pandora\Pandora\Agents\Agent;
use Pandora\Pandora\Automation\Enums\AutomationTrigger;
use Pandora\Pandora\Automation\Enums\ConcurrencyPolicy;
use Pandora\Pandora\Automation\Enums\MisfirePolicy;
use Pandora\Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Pandora\Runs\Enums\AutonomyLevel;
use Pandora\Pandora\Runs\Enums\TriggerType;
use Pandora\Pandora\Support\Concerns\PandoraModel;

/**
 * A standing instruction to start a run without anybody typing.
 *
 * The row is the whole definition: what wakes it, in which timezone, under
 * what condition, how far the agent may go, and how often it may wake at all.
 * ADR-0009 requires every autonomous action to be attributable to an
 * inspectable record, and this is that record.
 *
 * Two invariants are enforced here rather than at the call sites:
 *
 *  - `effectiveAutonomy()` is the LOWER of this row's level and the agent's.
 *    An automation must never be a way to widen what an agent may do, or the
 *    Automations page becomes a privilege escalation surface.
 *  - `next_run_at` is meaningful only for scheduled triggers. An event or
 *    webhook automation has none, so the scheduler cannot claim one.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $agent_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property AutomationTrigger $trigger_type
 * @property string|null $cron_expression
 * @property int|null $interval_seconds
 * @property Carbon|null $run_at
 * @property string $timezone
 * @property string|null $event_class
 * @property array<string, mixed>|null $condition
 * @property string|null $prompt
 * @property array<string, mixed>|null $context
 * @property array<string, mixed>|null $delivery
 * @property ConcurrencyPolicy $concurrency_policy
 * @property MisfirePolicy $misfire_policy
 * @property array<string, mixed>|null $retry_policy
 * @property AutonomyLevel $autonomy_level
 * @property int|null $autonomy_budget_runs
 * @property int $autonomy_budget_window_seconds
 * @property string|null $webhook_secret
 * @property bool $enabled
 * @property Carbon|null $last_run_at
 * @property Carbon|null $next_run_at
 * @property string|null $last_run_id
 * @property int $consecutive_failures
 * @property Carbon|null $disabled_at
 * @property string|null $disabled_reason
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class Automation extends Model
{
    use BelongsToTenant;
    use PandoraModel;
    use SoftDeletes;

    protected string $pandoraTable = 'automations';

    /**
     * Explicit fillable. An automation row decides what runs unattended, so
     * mass-assignment protection is load-bearing in exactly the way it is on
     * the agent.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id', 'agent_id', 'name', 'slug', 'description', 'trigger_type',
        'cron_expression', 'interval_seconds', 'run_at', 'timezone', 'event_class',
        'condition', 'prompt', 'context', 'delivery', 'concurrency_policy',
        'misfire_policy', 'retry_policy', 'autonomy_level', 'autonomy_budget_runs',
        'autonomy_budget_window_seconds', 'webhook_secret', 'enabled',
        'last_run_at', 'next_run_at', 'last_run_id', 'consecutive_failures',
        'disabled_at', 'disabled_reason', 'metadata',
    ];

    /**
     * The secret never leaves the row by accident: it is hidden from array and
     * JSON serialisation, so a Livewire property, a broadcast payload or an
     * audit metadata blob built from `toArray()` cannot carry it.
     *
     * @var list<string>
     */
    protected $hidden = ['webhook_secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger_type' => AutomationTrigger::class,
            'concurrency_policy' => ConcurrencyPolicy::class,
            'misfire_policy' => MisfirePolicy::class,
            'autonomy_level' => AutonomyLevel::class,
            'condition' => 'array',
            'context' => 'array',
            'delivery' => 'array',
            'retry_policy' => 'array',
            'metadata' => 'array',
            'enabled' => 'boolean',
            'interval_seconds' => 'integer',
            'autonomy_budget_runs' => 'integer',
            'autonomy_budget_window_seconds' => 'integer',
            'consecutive_failures' => 'integer',
            'webhook_secret' => 'encrypted',
            'run_at' => 'datetime',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    /** @return HasMany<AutomationRun, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(AutomationRun::class, 'automation_id');
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'automation_id');
    }

    /**
     * Due, as the scheduler sees it.
     *
     * @param Builder<self> $query
     */
    public function scopeDue(Builder $query, ?Carbon $now = null): void
    {
        $query->where('enabled', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now ?? now());
    }

    /** @param Builder<self> $query */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('enabled', true);
    }

    /**
     * The autonomy this automation actually gets: the lower of its own level
     * and the agent's.
     *
     * Enforced here rather than at each call site, because there are four call
     * sites -- the scheduler, the event listener, the webhook and the manual
     * run button -- and the one that forgot would be the interesting one.
     */
    public function effectiveAutonomy(Agent $agent): AutonomyLevel
    {
        $order = [
            AutonomyLevel::ObserveOnly->value => 0,
            AutonomyLevel::Suggest->value => 1,
            AutonomyLevel::ActWithApproval->value => 2,
            AutonomyLevel::ActWithinPolicy->value => 3,
        ];

        return $order[$this->autonomy_level->value] <= $order[$agent->autonomy_level->value]
            ? $this->autonomy_level
            : $agent->autonomy_level;
    }

    /**
     * The run trigger type this automation produces.
     *
     * A heartbeat automation produces `heartbeat` runs, a webhook one produces
     * `webhook` runs. The run stays attributable after the automation is gone.
     */
    public function runTrigger(): TriggerType
    {
        return match ($this->trigger_type) {
            AutomationTrigger::Event => TriggerType::Event,
            AutomationTrigger::Webhook => TriggerType::Webhook,
            AutomationTrigger::Heartbeat => TriggerType::Heartbeat,
            default => TriggerType::Schedule,
        };
    }

    public function isScheduled(): bool
    {
        return $this->trigger_type->isScheduled();
    }

    /**
     * The instruction sent to the agent. Never empty: a run with no input
     * gives the model nothing to act on, and "" is not a question.
     */
    public function instruction(): string
    {
        $prompt = trim((string) $this->prompt);

        return $prompt === ''
            ? sprintf('Automation "%s" fired. Decide whether anything needs doing, and report.', $this->name)
            : $prompt;
    }

    /**
     * How many consecutive failures this automation tolerates before it turns
     * itself off. Null means it never does.
     */
    public function failureLimit(): ?int
    {
        $limit = $this->retry_policy['disable_after_failures'] ?? null;

        return is_numeric($limit) ? (int) $limit : null;
    }
}
