<?php

declare(strict_types=1);

namespace Pandora\Runs;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Pandora\Runs\Enums\RunState;

/**
 * Prevents two workers executing the same run concurrently.
 *
 * Two mechanisms, because one is not enough:
 *
 *   1. An atomic cache lock -- fast, but lost on a cache flush and unavailable
 *      on cache drivers that do not support locking.
 *   2. A database ownership lease (`owner_token` + `owner_expires_at`) -- the
 *      authority. Survives a cache flush and works on every driver.
 *
 * The lease TTL always exceeds the per-iteration timeout, so a lease cannot
 * expire while a worker is still legitimately holding it. A run whose lease
 * HAS expired while still `running` is stalled, and the health check recovers it.
 */
final class RunLock
{
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly ConnectionInterface $connection,
        private readonly int $ttlSeconds = 900,
    ) {}

    /**
     * Acquire ownership of a run, returning the owner token, or null if
     * another worker holds it.
     */
    public function acquire(string $runId): ?string
    {
        $token = (string) Str::ulid();

        if (! $this->cacheLock($runId, $token)) {
            // The cache said no. Before believing it, consult the authority:
            // if the database lease has expired then the previous holder is
            // gone (a killed worker) and the cache entry is stale. Trusting
            // the cache here would strand the run until its entry aged out.
            if (! $this->databaseLeaseExpired($runId)) {
                return null;
            }

            $this->forceReleaseCacheLock($runId);

            if (! $this->cacheLock($runId, $token)) {
                return null;
            }
        }

        // The database lease is the authority. Claim it only if unowned or expired.
        $claimed = $this->connection->transaction(function () use ($runId, $token): bool {
            /** @var Run|null $run */
            $run = Run::query()->lockForUpdate()->find($runId);

            if ($run === null || $run->state->isTerminal()) {
                return false;
            }

            $leaseHeld = $run->owner_token !== null
                && $run->owner_expires_at !== null
                && $run->owner_expires_at->isFuture();

            if ($leaseHeld) {
                return false;
            }

            $run->forceFill([
                'owner_token' => $token,
                'owner_expires_at' => now()->addSeconds($this->ttlSeconds),
            ])->save();

            return true;
        });

        if (! $claimed) {
            $this->releaseCacheLock($runId, $token);

            return null;
        }

        return $token;
    }

    /**
     * Extend the lease during a long iteration.
     */
    public function renew(string $runId, string $token): bool
    {
        return Run::query()
            ->whereKey($runId)
            ->where('owner_token', $token)
            ->update(['owner_expires_at' => now()->addSeconds($this->ttlSeconds)]) === 1;
    }

    public function release(string $runId, string $token): void
    {
        Run::query()
            ->whereKey($runId)
            ->where('owner_token', $token)
            ->update(['owner_token' => null, 'owner_expires_at' => null]);

        $this->releaseCacheLock($runId, $token);
    }

    public function isHeldBy(string $runId, string $token): bool
    {
        return Run::query()
            ->whereKey($runId)
            ->where('owner_token', $token)
            ->where('owner_expires_at', '>', now())
            ->exists();
    }

    /**
     * Runs whose lease expired while they were still executing -- a crashed or
     * killed worker.
     *
     * @return Collection<int, Run>
     */
    public function stalledRuns(int $limit = 100): Collection
    {
        return Run::query()
            ->whereIn('state', [RunState::Running->value, RunState::Starting->value, RunState::WaitingForTool->value])
            ->whereNotNull('owner_expires_at')
            ->where('owner_expires_at', '<', now())
            ->limit($limit)
            ->get();
    }

    /**
     * Whether the database lease is unowned or expired -- i.e. whoever held
     * this run is no longer running.
     */
    private function databaseLeaseExpired(string $runId): bool
    {
        /** @var Run|null $run */
        $run = Run::query()->find($runId);

        if ($run === null) {
            return false;
        }

        return $run->owner_token === null
            || $run->owner_expires_at === null
            || $run->owner_expires_at->isPast();
    }

    private function cacheLock(string $runId, string $token): bool
    {
        $store = $this->cache->store()->getStore();

        if (! $store instanceof LockProvider) {
            // Driver cannot lock; the database lease alone is sufficient.
            return true;
        }

        return $store->lock($this->lockKey($runId), $this->ttlSeconds, $token)->get();
    }

    private function releaseCacheLock(string $runId, string $token): void
    {
        $store = $this->cache->store()->getStore();

        if ($store instanceof LockProvider) {
            $store->lock($this->lockKey($runId), $this->ttlSeconds, $token)->forceRelease();
        }
    }

    private function forceReleaseCacheLock(string $runId): void
    {
        $store = $this->cache->store()->getStore();

        if ($store instanceof LockProvider) {
            $store->lock($this->lockKey($runId), $this->ttlSeconds)->forceRelease();
        }
    }

    private function lockKey(string $runId): string
    {
        return "pandora:run:{$runId}";
    }
}
