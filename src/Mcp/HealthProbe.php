<?php

declare(strict_types=1);

namespace Pandora\Mcp;

use Pandora\Audit\AuditLogger;
use Pandora\Exceptions\McpDenied;
use Pandora\Mcp\Enums\ServerHealth;
use Pandora\Mcp\Transport\TransportFactory;

/**
 * Ask a server whether it is there, and write down the answer.
 *
 * The same rule Phase 3 applies to a degraded provider, for the same reason:
 * an unhealthy server's tools are **unavailable rather than slow**. A run that
 * waits on a server known to be down has converted a clear failure into a
 * timeout, and a timeout is the failure operators debug last because it looks
 * like load.
 *
 * Degradation takes a run of failures rather than one, because a server that
 * flapped on a single transient reset would pull its tools out from under
 * every agent for no reason anybody could later explain.
 */
final readonly class HealthProbe
{
    public function __construct(
        private TransportFactory $transports,
        private AuditLogger $audit,
    ) {}

    public function probe(McpServer $server): ServerHealth
    {
        try {
            $this->transports->for($server)->listTools($server);
        } catch (McpDenied $e) {
            return $this->degrade($server, $e);
        }

        $server->update([
            'health' => ServerHealth::Healthy->value,
            'health_message' => null,
            'last_probed_at' => now(),
            'metadata' => ['consecutive_failures' => 0] + ($server->metadata ?? []),
        ]);

        return ServerHealth::Healthy;
    }

    /**
     * One failure is degraded; a second is unhealthy.
     *
     * `Degraded` still serves its tools. It is a state that says "this has
     * failed once and we have not yet concluded anything", which is the honest
     * reading of a single reset connection.
     */
    private function degrade(McpServer $server, McpDenied $e): ServerHealth
    {
        $metadata = $server->metadata ?? [];
        $failures = (int) ($metadata['consecutive_failures'] ?? 0) + 1;

        $health = $failures >= 2 ? ServerHealth::Unhealthy : ServerHealth::Degraded;

        $server->update([
            'health' => $health->value,
            'health_message' => mb_substr($e->getMessage(), 0, 500),
            'last_probed_at' => now(),
            'metadata' => ['consecutive_failures' => $failures] + $metadata,
        ]);

        $this->audit->record(
            action: 'mcp.server_unreachable',
            targetType: 'mcp_server',
            targetId: (string) $server->getKey(),
            severity: 'warning',
            metadata: [
                'server' => $server->slug,
                'health' => $health->value,
                'consecutive_failures' => $failures,
                'reason' => $e->reason,
            ],
        );

        return $health;
    }
}
