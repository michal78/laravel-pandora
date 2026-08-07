<?php

declare(strict_types=1);

namespace Pandora\Providers\Health;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Pandora\Audit\AuditLogger;
use Pandora\Providers\Data\ProviderHealth;

/**
 * What the router, the Providers page and `pandora:status` all read.
 *
 * Degradation is deliberately hysteretic: it takes a RUN of failures to mark a
 * provider down, and a single success to bring it back. A provider that
 * flapped in and out of the fallback chain on every transient timeout would be
 * worse than no health tracking at all, because runs would scatter across
 * models for no reason anybody could later explain.
 */
final class ProviderHealthMonitor
{
    public function __construct(
        private readonly Config $config,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Record the outcome of a probe or a real call.
     */
    public function record(string $providerKey, ProviderHealth $health): ProviderHealthRecord
    {
        return $health->status === 'healthy'
            ? $this->recordSuccess($providerKey, $health->latencyMs)
            : $this->recordFailure($providerKey, $health->message ?? $health->status);
    }

    public function recordSuccess(string $providerKey, ?int $latencyMs = null): ProviderHealthRecord
    {
        $record = $this->rowFor($providerKey);
        $wasDegraded = ! $record->isUsable();

        $record->fill([
            'status' => 'healthy',
            'latency_ms' => $latencyMs,
            'consecutive_failures' => 0,
            'consecutive_successes' => $record->consecutive_successes + 1,
            'last_error' => null,
            'checked_at' => Carbon::now(),
            'degraded_since' => null,
        ])->save();

        if ($wasDegraded) {
            $this->audit->record(
                action: 'provider.recovered',
                targetType: ProviderHealthRecord::class,
                targetId: $record->id,
                metadata: ['provider' => $providerKey, 'latency_ms' => $latencyMs],
            );
        }

        return $record;
    }

    public function recordFailure(string $providerKey, string $reason): ProviderHealthRecord
    {
        $record = $this->rowFor($providerKey);

        $failures = $record->consecutive_failures + 1;
        $threshold = $this->failureThreshold();
        $degraded = $failures >= $threshold;

        $wasUsable = $record->isUsable();

        $record->fill([
            'status' => $degraded ? 'degraded' : $record->status,
            'consecutive_failures' => $failures,
            'consecutive_successes' => 0,
            // The reason is stored for an operator, never fed back to a model.
            'last_error' => mb_substr($reason, 0, 1_000),
            'checked_at' => Carbon::now(),
            'degraded_since' => $degraded ? ($record->degraded_since ?? Carbon::now()) : null,
        ])->save();

        if ($degraded && $wasUsable) {
            $this->audit->record(
                action: 'provider.degraded',
                targetType: ProviderHealthRecord::class,
                targetId: $record->id,
                severity: 'warning',
                metadata: [
                    'provider' => $providerKey,
                    'consecutive_failures' => $failures,
                    'threshold' => $threshold,
                    'reason' => mb_substr($reason, 0, 200),
                ],
            );
        }

        return $record;
    }

    public function status(string $providerKey): ProviderHealth
    {
        $record = ProviderHealthRecord::query()->where('provider_key', $providerKey)->first();

        return $record?->toHealth() ?? ProviderHealth::unknown();
    }

    /**
     * Whether the router may route to this provider.
     *
     * Unknown counts as usable. A provider nobody has probed yet is not
     * evidence of a problem, and refusing to use it would make health tracking
     * an outage of its own on a fresh installation.
     */
    public function isUsable(string $providerKey): bool
    {
        if ($this->config->get('pandora.providers.health.enabled', true) !== true) {
            return true;
        }

        $record = ProviderHealthRecord::query()->where('provider_key', $providerKey)->first();

        return $record === null || $record->isUsable();
    }

    /**
     * @return Collection<int, ProviderHealthRecord>
     */
    public function all(): Collection
    {
        return ProviderHealthRecord::query()->orderBy('provider_key')->get();
    }

    private function failureThreshold(): int
    {
        return max(1, (int) $this->config->get('pandora.providers.health.failure_threshold', 3));
    }

    private function rowFor(string $providerKey): ProviderHealthRecord
    {
        // The defaults are stated here as well as in the schema: a column
        // default only applies to a row the database inserts on its own, and
        // this one always arrives fully specified.
        /** @var ProviderHealthRecord $record */
        $record = ProviderHealthRecord::query()->firstOrNew(
            ['provider_key' => $providerKey],
            ['status' => 'unknown', 'consecutive_failures' => 0, 'consecutive_successes' => 0],
        );

        return $record;
    }
}
