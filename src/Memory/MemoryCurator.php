<?php

declare(strict_types=1);

namespace Pandora\Pandora\Memory;

use Illuminate\Support\Facades\Date;
use Pandora\Pandora\Audit\AuditLogger;
use Pandora\Pandora\Exceptions\AuthorizationDenied;
use Pandora\Pandora\Memory\Embeddings\MemoryEmbedder;
use Pandora\Pandora\Memory\Enums\MemoryStatus;
use Pandora\Pandora\UI\PandoraGate;

/**
 * The human half of memory: approving, rejecting, forgetting, expiring.
 *
 * Every method here is an operator action behind `pandora.memory.manage`,
 * except the expiry sweep, which is a scheduled job and belongs to nobody.
 *
 * The asymmetry with `MemoryWriter` is deliberate and is the point of the
 * whole curation model. An agent may *propose* that something be remembered.
 * Only a person can make it something the agent will repeat.
 */
final class MemoryCurator
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MemoryEmbedder $embedder,
    ) {}

    /**
     * Promote a suggested memory to active.
     *
     * @throws AuthorizationDenied
     */
    public function approve(MemoryItem $item, ?string $comment = null): MemoryItem
    {
        PandoraGate::authorize('memory.manage');

        $item->update([
            'status' => MemoryStatus::Active->value,
            'metadata' => array_merge($item->metadata ?? [], array_filter([
                'approval_comment' => $comment,
                'approved_at' => Date::now()->toIso8601String(),
            ])),
        ]);

        // Embedded only now. Embedding on write would put an unapproved claim
        // into the vector index, where the scope re-filter would keep it out
        // of answers but a store dump would still contain it.
        $this->embedder->embed($item);

        $this->audit->record(
            action: 'memory.approved',
            targetType: 'memory_item',
            targetId: (string) $item->getKey(),
            metadata: ['scope' => $item->scope->value, 'comment' => $comment],
        );

        return $item;
    }

    /**
     * Refuse a suggested memory.
     *
     * Kept as `rejected` rather than deleted, so the same suggestion is not
     * re-proposed and re-reviewed forever.
     *
     * @throws AuthorizationDenied
     */
    public function reject(MemoryItem $item, ?string $comment = null): MemoryItem
    {
        PandoraGate::authorize('memory.manage');

        $item->update([
            'status' => MemoryStatus::Rejected->value,
            'metadata' => array_merge($item->metadata ?? [], array_filter([
                'rejection_comment' => $comment,
                'rejected_at' => Date::now()->toIso8601String(),
            ])),
        ]);

        $this->audit->record(
            action: 'memory.rejected',
            targetType: 'memory_item',
            targetId: (string) $item->getKey(),
            metadata: ['scope' => $item->scope->value, 'comment' => $comment],
        );

        return $item;
    }

    /**
     * Forget a memory.
     *
     * The vector is hard-deleted while the row is only soft-deleted, and that
     * asymmetry is the feature. "Forget that" has to remove the thing that
     * makes a memory retrievable; the row is kept so an audit can still show
     * what was forgotten and when.
     *
     * @throws AuthorizationDenied
     */
    public function forget(MemoryItem $item, ?string $reason = null): void
    {
        PandoraGate::authorize('memory.manage');

        $this->embedder->forget($item);

        $this->audit->record(
            action: 'memory.forgotten',
            targetType: 'memory_item',
            targetId: (string) $item->getKey(),
            metadata: [
                'scope' => $item->scope->value,
                'type' => $item->type->value,
                'reason' => $reason,
            ],
        );

        $item->delete();
    }

    /**
     * Expire everything past its date.
     *
     * Housekeeping, not the guarantee. Retrieval already excludes an expired
     * item by predicate, so a sweep that has not run for a week costs index
     * size and nothing else -- which is exactly the property that lets this be
     * a scheduled job rather than something on the read path.
     *
     * @return int how many were expired
     */
    public function sweepExpired(int $limit = 1000): int
    {
        $due = MemoryItem::acrossAllTenants()
            ->whereIn('status', [MemoryStatus::Active->value, MemoryStatus::Suggested->value])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', Date::now())
            ->limit($limit)
            ->get();

        foreach ($due as $item) {
            // The vector goes even though the row stays. An expired memory
            // with a live vector is still findable by the path that matters.
            $this->embedder->forget($item);

            $item->update(['status' => MemoryStatus::Expired->value]);

            $this->audit->record(
                action: 'memory.expired',
                targetType: 'memory_item',
                targetId: (string) $item->getKey(),
                metadata: ['scope' => $item->scope->value, 'type' => $item->type->value],
            );
        }

        return $due->count();
    }
}
