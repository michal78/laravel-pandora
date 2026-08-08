<?php

declare(strict_types=1);

namespace Pandora\Mcp\Enums;

/**
 * How we reach a remote server.
 *
 * `Stdio` is in this enum and disabled in configuration, which is the honest
 * shape: the transport exists, a deployment may want it, and it does not
 * happen because somebody wrote a row. Executing a local binary named by a
 * database row turns write access to one table into arbitrary local execution
 * (ADR-0014).
 */
enum McpTransport: string
{
    case Http = 'http';
    case Sse = 'sse';
    case Stdio = 'stdio';

    /**
     * The configuration key that governs this transport, named in the refusal
     * so nobody has to guess which switch was not thrown.
     */
    public function configKey(): string
    {
        return 'pandora.mcp.transports.'.$this->value.'.enabled';
    }

    public function isEnabled(): bool
    {
        return (bool) config($this->configKey(), false);
    }

    public function label(): string
    {
        return match ($this) {
            self::Http => 'HTTP',
            self::Sse => 'HTTP + SSE',
            self::Stdio => 'stdio (local process)',
        };
    }
}
