<?php

declare(strict_types=1);

namespace Pandora\Pandora\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Pandora\Pandora\Exceptions\PandoraException;
use Pandora\Pandora\Providers\Health\ProviderHealthMonitor;
use Pandora\Pandora\Providers\ProviderManager;

/**
 * Ask every configured provider whether it is there.
 *
 * Scheduled on the maintenance queue, deliberately away from the queue runs
 * use: a probe that queued behind a hundred agent iterations would report the
 * health of ten minutes ago.
 *
 * Nothing here may throw. A health probe exists to make failures visible, and
 * a probe that fails loudly enough to retry, alert or fail a run would be a
 * new source of exactly the noise it was built to explain.
 */
final class ProbeProviderHealth implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        /** Probe one connection, or every configured one when null. */
        public readonly ?string $providerKey = null,
    ) {
        /** @var string|null $queue */
        $queue = config('pandora.queues.maintenance');
        /** @var string|null $connection */
        $connection = config('pandora.queues.connection');

        $this->onQueue($queue);
        $this->onConnection($connection);
    }

    public function handle(ProviderManager $providers, ProviderHealthMonitor $monitor): void
    {
        if (config('pandora.providers.health.enabled', true) !== true) {
            return;
        }

        $keys = $this->providerKey !== null
            ? [$this->providerKey]
            : $providers->configuredKeys();

        foreach ($keys as $key) {
            $this->probe($key, $providers, $monitor);
        }
    }

    private function probe(string $key, ProviderManager $providers, ProviderHealthMonitor $monitor): void
    {
        try {
            $monitor->record($key, $providers->provider($key)->health());
        } catch (PandoraException $e) {
            // A misconfigured or unreachable provider is exactly what this job
            // is for. It is recorded, not raised.
            $monitor->recordFailure($key, $e->getMessage());
        }
    }
}
