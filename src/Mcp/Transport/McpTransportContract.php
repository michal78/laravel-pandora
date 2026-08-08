<?php

declare(strict_types=1);

namespace Pandora\Mcp\Transport;

use Pandora\Exceptions\McpDenied;
use Pandora\Mcp\McpServer;

/**
 * How bytes get to a remote server and back.
 *
 * Two methods, because this phase does two things: ask what tools exist, and
 * call one. MCP has more than that — resources, prompts, sampling, roots — and
 * they are deliberately absent (ADR-0014). Sampling in particular inverts the
 * trust direction: a remote server asking us to spend a model call on its
 * behalf is a budget hole with a protocol around it, and an interface with a
 * method for it is an interface somebody will implement.
 *
 * Every implementation is responsible for three things the layers above
 * assume: a timeout, a response size cap, and turning any transport failure
 * into `McpDenied` rather than letting a client library's exception escape
 * into the middle of a run.
 */
interface McpTransportContract
{
    /**
     * The raw tool descriptors the server claims to offer.
     *
     * Returned as decoded arrays and nothing more. Validating them, bounding
     * the descriptions and deciding which are publishable belongs to
     * discovery, because those are policy and this is plumbing.
     *
     * @return list<array<string, mixed>>
     *
     * @throws McpDenied
     */
    public function listTools(McpServer $server): array;

    /**
     * Call one tool and return what came back.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     *
     * @throws McpDenied
     */
    public function callTool(McpServer $server, string $remoteName, array $arguments): array;
}
