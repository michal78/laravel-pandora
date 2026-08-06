<?php

declare(strict_types=1);

namespace Pandora\Pandora\Memory;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Date;
use Pandora\Pandora\Agents\Agent;
use Pandora\Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Pandora\Exceptions\InvalidMemoryScope;
use Pandora\Pandora\Memory\Enums\MemoryScope;
use Pandora\Pandora\Memory\Enums\MemorySensitivity;
use Pandora\Pandora\Memory\Enums\MemorySource;
use Pandora\Pandora\Memory\Enums\MemoryStatus;
use Pandora\Pandora\Memory\Enums\MemoryType;
use Pandora\Pandora\Runs\Run;
use Pandora\Pandora\Support\Concerns\PandoraModel;

/**
 * One thing an agent knows, and the constraint on who it may be said to.
 *
 * Everything about this model exists to answer one question at retrieval time:
 * *whose was this, and who is standing here now?* The scope pair is the answer,
 * and `scopeRetrievable()` is the only place the answer is computed. A query
 * that filters memory any other way is a leak waiting for a code review to
 * miss it, which is why retrieval lives behind `MemoryRetriever` rather than
 * being available as a builder anyone can assemble.
 *
 * The status vocabulary is not a workflow for its own sake. A sensitive fact
 * lands as `Suggested` and is invisible to every agent until a human approves
 * it -- an agent that could read back its own unapproved suggestion has
 * approved it itself.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property MemoryScope $scope
 * @property string|null $scope_id
 * @property string|null $agent_id
 * @property MemoryType $type
 * @property string|null $title
 * @property string $content
 * @property array<string, mixed>|null $structured
 * @property MemorySource $source
 * @property string|null $source_run_id
 * @property array<string, mixed>|null $provenance
 * @property int $confidence
 * @property MemorySensitivity $sensitivity
 * @property MemoryStatus $status
 * @property string|null $approval_id
 * @property CarbonInterface|null $expires_at
 * @property string|null $embedding_id
 * @property CarbonInterface|null $last_retrieved_at
 * @property int $retrieval_count
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
final class MemoryItem extends Model
{
    use BelongsToTenant;
    use PandoraModel;
    use SoftDeletes;

    protected string $pandoraTable = 'memory_items';

    /**
     * Mirrored from the schema deliberately. A column default only applies at
     * the database, so a freshly created instance would carry a null status
     * until someone refreshed it -- and `isRetrievable()` on that instance
     * would fatal rather than answer.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'sensitivity' => 'normal',
        'confidence' => 100,
        'retrieval_count' => 0,
    ];

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'scope', 'scope_id', 'agent_id', 'type', 'title', 'content',
        'structured', 'source', 'source_run_id', 'provenance', 'confidence',
        'sensitivity', 'status', 'approval_id', 'expires_at', 'embedding_id',
        'last_retrieved_at', 'retrieval_count', 'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => MemoryScope::class,
            'type' => MemoryType::class,
            'source' => MemorySource::class,
            'sensitivity' => MemorySensitivity::class,
            'status' => MemoryStatus::class,
            'structured' => 'array',
            'provenance' => 'array',
            'metadata' => 'array',
            'confidence' => 'integer',
            'retrieval_count' => 'integer',
            'expires_at' => 'datetime',
            'last_retrieved_at' => 'datetime',
        ];
    }

    /**
     * The scope pair is validated on the way into the table, not on the way
     * out of it. Retrieval is only as safe as the rows it filters, and a row
     * whose scope identifies nobody will eventually match somebody.
     */
    protected static function booted(): void
    {
        self::saving(function (self $item): void {
            $requiresId = $item->scope->requiresScopeId();

            if ($requiresId && ($item->scope_id === null || $item->scope_id === '')) {
                throw InvalidMemoryScope::missingScopeId($item->scope);
            }

            if (! $requiresId && $item->scope_id !== null) {
                throw InvalidMemoryScope::unexpectedScopeId($item->scope);
            }
        });

        // Checked on `creating` rather than `saving`, and that ordering is the
        // whole point: `BelongsToTenant` stamps `tenant_id` on `creating`, and
        // `saving` runs before it. Validated a moment earlier, a global memory
        // written inside a tenant would pass the check, get stamped, and
        // become a row no retrieval can ever return -- present in the table,
        // absent from every answer, and impossible to notice.
        self::creating(static function (self $item): void {
            if ($item->scope === MemoryScope::Global && $item->tenant_id !== null) {
                throw InvalidMemoryScope::tenantedGlobal($item->tenant_id);
            }
        });

        self::updating(static function (self $item): void {
            if ($item->scope === MemoryScope::Global && $item->tenant_id !== null) {
                throw InvalidMemoryScope::tenantedGlobal($item->tenant_id);
            }
        });
    }

    /**
     * Overridden from `BelongsToTenant` only to carry the precise builder
     * type. Retrieval reapplies the tenant predicate itself -- per branch,
     * because installation-wide memory is tenant-less -- and it needs a
     * `Builder<self>` to do that under static analysis.
     *
     * @return Builder<self>
     */
    public static function acrossAllTenants(): Builder
    {
        return self::query()->withoutGlobalScope('pandora_tenant');
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    /** @return BelongsTo<Run, $this> */
    public function sourceRun(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'source_run_id');
    }

    /** @return BelongsTo<Embedding, $this> */
    public function embedding(): BelongsTo
    {
        return $this->belongsTo(Embedding::class, 'embedding_id');
    }

    /**
     * Everything that may be said out loud, before scope is considered.
     *
     * Expiry is asserted here as well as by the sweep. If retrieval trusted
     * the sweep, a worker down for a day would mean a day of expired facts
     * still being repeated -- the predicate is the guarantee, the sweep is
     * housekeeping.
     *
     * @param Builder<self> $query
     */
    public function scopeRetrievable(Builder $query): void
    {
        $query
            ->where('status', MemoryStatus::Active->value)
            ->where(function (Builder $q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', Date::now());
            });
    }

    /** @param Builder<self> $query */
    public function scopeAwaitingReview(Builder $query): void
    {
        $query->where('status', MemoryStatus::Suggested->value);
    }

    public function isRetrievable(): bool
    {
        return $this->status->retrievable() && ! $this->hasExpired();
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * The text a vector is built from. Title and content together, because a
     * title carrying the only distinguishing word is common and embedding the
     * body alone loses it.
     */
    public function embeddableText(): string
    {
        return trim(($this->title ?? '').PHP_EOL.$this->content);
    }
}
