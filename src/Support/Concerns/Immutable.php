<?php

declare(strict_types=1);

namespace Pandora\Pandora\Support\Concerns;

use Pandora\Pandora\Exceptions\ImmutableRecord;

/**
 * Append-only records: run steps and audit logs.
 *
 * Immutability is enforced here rather than left to convention, because a
 * trace that can be quietly rewritten is not a trace. Pruning under a
 * retention policy is the only permitted deletion, and goes through the
 * dedicated prune path rather than model deletes.
 */
trait Immutable
{
    public static function bootImmutable(): void
    {
        static::updating(function (self $model): void {
            throw ImmutableRecord::cannotUpdate($model::class, (string) $model->getKey());
        });

        static::deleting(function (self $model): void {
            if (! $model->allowsPruning()) {
                throw ImmutableRecord::cannotDelete($model::class, (string) $model->getKey());
            }
        });
    }

    /**
     * Set by the retention pruner, which is the only legitimate deleter.
     */
    protected bool $pruning = false;

    public function markForPruning(): static
    {
        $this->pruning = true;

        return $this;
    }

    public function allowsPruning(): bool
    {
        return $this->pruning;
    }
}
