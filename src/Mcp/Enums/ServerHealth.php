<?php

declare(strict_types=1);

namespace Pandora\Mcp\Enums;

/**
 * Whether a remote server answered when we last asked.
 *
 * `Unknown` is the starting state and is deliberately not `Healthy`: a server
 * nobody has probed has not been shown to work, and a default that assumes it
 * does is a default that makes the first failure look like a bug in the run
 * rather than a server that was never reachable.
 */
enum ServerHealth: string
{
    case Unknown = 'unknown';
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unhealthy = 'unhealthy';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
