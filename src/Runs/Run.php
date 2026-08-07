<?php

declare(strict_types=1);

namespace Pandora\Runs;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Pandora\Agents\Agent;
use Pandora\Conversations\Conversation;
use Pandora\Conversations\Session;
use Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Messages\Message;
use Pandora\Runs\Enums\AutonomyLevel;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Enums\TriggerType;
use Pandora\Support\Concerns\PandoraModel;

/**
 * One bounded execution of an agent. The central unit of work.
 *
 * A run is durable, queued, resumable, cancellable, budgeted and traced --
 * closer to a job with a state machine than to a chat request.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $agent_id
 * @property string|null $conversation_id
 * @property string $session_id
 * @property string|null $parent_run_id
 * @property int $delegation_depth
 * @property list<string>|null $effective_tools
 * @property string|null $delegated_tool_execution_id
 * @property RunState $state
 * @property TriggerType $trigger_type
 * @property AutonomyLevel|null $autonomy_level
 * @property string|null $automation_id
 * @property string $correlation_id
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string|null $provider_key
 * @property string|null $model_key
 * @property string|null $input
 * @property string|null $output
 * @property int $iterations
 * @property int $tool_calls_count
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string|null $owner_token
 * @property Carbon|null $owner_expires_at
 * @property Carbon|null $cancel_requested_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $deadline_at
 * @property string|null $error_class
 * @property string|null $error_message
 * @property string|null $trigger_id
 * @property string|null $idempotency_key
 * @property int $cost_minor
 * @property string $currency
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $queued_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Run extends Model
{
    use BelongsToTenant;
    use PandoraModel;

    protected string $pandoraTable = 'runs';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'agent_id', 'conversation_id', 'session_id',
        'parent_run_id', 'delegation_depth', 'effective_tools', 'delegated_tool_execution_id',
        'state', 'trigger_type', 'trigger_id',
        'autonomy_level', 'automation_id',
        'correlation_id', 'idempotency_key', 'actor_type', 'actor_id',
        'provider_key', 'model_key', 'input', 'output',
        'iterations', 'tool_calls_count', 'input_tokens', 'output_tokens',
        'cost_minor', 'currency', 'owner_token', 'owner_expires_at',
        'cancel_requested_at', 'queued_at', 'started_at', 'finished_at',
        'deadline_at', 'error_class', 'error_message', 'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => RunState::class,
            'trigger_type' => TriggerType::class,
            'autonomy_level' => AutonomyLevel::class,
            'metadata' => 'array',
            'effective_tools' => 'array',
            'delegation_depth' => 'integer',
            'iterations' => 'integer',
            'tool_calls_count' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_minor' => 'integer',
            'owner_expires_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'deadline_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    /** @return BelongsTo<Session, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class, 'session_id');
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_run_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_run_id');
    }

    /** @return HasMany<RunStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(RunStep::class, 'run_id')->orderBy('sequence');
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'run_id');
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNotIn('state', array_map(
            static fn (RunState $state): string => $state->value,
            array_filter(RunState::cases(), static fn (RunState $s): bool => $s->isTerminal()),
        ));
    }

    /** @param Builder<self> $query */
    public function scopeStalled(Builder $query): void
    {
        $query
            ->whereIn('state', [RunState::Running->value, RunState::Starting->value])
            ->whereNotNull('owner_expires_at')
            ->where('owner_expires_at', '<', now());
    }

    public function isCancelRequested(): bool
    {
        return $this->cancel_requested_at !== null;
    }

    public function hasExceededDeadline(): bool
    {
        return $this->deadline_at !== null && $this->deadline_at->isPast();
    }

    public function isDelegated(): bool
    {
        return $this->parent_run_id !== null;
    }

    /**
     * This run's ancestors, nearest first.
     *
     * Walked with an explicit bound rather than a `while (true)`: the depth
     * column is a tinyint an operator can raise, and a parent chain that
     * somehow cycled would otherwise hang a worker rather than fail. The bound
     * is generous -- reaching it means the data is wrong, not that a legitimate
     * tree was truncated.
     *
     * @return list<self>
     */
    public function ancestors(int $limit = 64): array
    {
        $ancestors = [];
        $current = $this;

        while ($current->parent_run_id !== null && count($ancestors) < $limit) {
            /** @var self|null $parent */
            $parent = self::query()->find($current->parent_run_id);

            if ($parent === null) {
                break;
            }

            $ancestors[] = $parent;
            $current = $parent;
        }

        return $ancestors;
    }

    /**
     * The run at the top of this run's tree -- itself, when it has no parent.
     *
     * The unit a budget is drawn against. A limit that reset per child would
     * not be a limit, it would be a multiplier with a depth exponent.
     */
    public function treeRoot(): self
    {
        $ancestors = $this->ancestors();

        return $ancestors === [] ? $this : $ancestors[count($ancestors) - 1];
    }

    /**
     * Every run id in this run's tree, from the root down.
     *
     * Read breadth-first in one query per level rather than a recursive CTE,
     * which SQLite, MySQL, MariaDB and PostgreSQL do not agree on the syntax
     * for. Delegation is sequential and depth-bounded this phase, so the tree
     * is small and the level count is `max_delegation_depth`.
     *
     * @return list<string>
     */
    public function treeRunIds(): array
    {
        $root = $this->treeRoot();
        $ids = [(string) $root->getKey()];
        $frontier = $ids;

        do {
            /** @var list<string> $children */
            $children = self::query()
                ->whereIn('parent_run_id', $frontier)
                ->whereNotIn('id', $ids)
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();

            // Excluding ids already seen is what makes this terminate: a
            // parent chain that somehow cycled shrinks the frontier to empty
            // rather than walking it forever.
            $ids = array_merge($ids, $children);
            $frontier = $children;
        } while ($frontier !== []);

        return $ids;
    }

    public function nextStepSequence(): int
    {
        return (int) $this->steps()->max('sequence') + 1;
    }

    public function durationMs(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInMilliseconds($this->finished_at ?? now());
    }
}
