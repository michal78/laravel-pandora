<?php

declare(strict_types=1);

namespace Pandora\Mcp\Transport;

use Illuminate\Contracts\Container\Container;
use Pandora\Exceptions\McpDenied;
use Pandora\Mcp\Enums\McpTransport;
use Pandora\Mcp\McpServer;

/**
 * Which transport a server gets, and whether it gets one at all.
 *
 * The gate is here rather than inside each transport, so that a disabled
 * transport is never CONSTRUCTED. A check inside `StdioTransport::call()`
 * would leave a class that spawns processes one forgotten early-return away
 * from doing it; a factory that refuses to build it means the code path does
 * not exist for a deployment that did not ask for it.
 *
 * stdio is the reason this matters. It executes a local binary named by a
 * database row, so write access to `pandora_mcp_servers` becomes arbitrary
 * local execution. Reasonable on a laptop, never reasonable by default
 * (ADR-0014).
 */
final readonly class TransportFactory
{
    public function __construct(private Container $container) {}

    /**
     * @throws McpDenied
     */
    public function for(McpServer $server): McpTransportContract
    {
        $transport = $server->transport;

        if (! $transport->isEnabled()) {
            // Names the key. An operator guessing which switch governs stdio
            // is an operator who ends up enabling more than they meant to.
            throw McpDenied::transportDisabled($transport->value, $transport->configKey());
        }

        return match ($transport) {
            McpTransport::Http, McpTransport::Sse => $this->container->make(HttpTransport::class),
            McpTransport::Stdio => $this->container->make(StdioTransport::class),
        };
    }
}
