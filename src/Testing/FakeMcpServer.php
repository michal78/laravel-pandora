<?php

declare(strict_types=1);

namespace Pandora\Testing;

use Pandora\Exceptions\McpDenied;
use Pandora\Mcp\McpServer;
use Pandora\Mcp\Transport\McpTransportContract;

/**
 * A remote MCP server that misbehaves on purpose.
 *
 * This ships in `src/`, not in `tests/`, and that is deliberate: it is a
 * deliverable of Phase 6 and it is useful to anyone integrating an MCP server
 * against Pandora. Every claim this phase makes about remote tools is a claim
 * about how we behave when the other end is hostile, slow, enormous or simply
 * different from yesterday — and a suite that only ever ran against a
 * well-behaved server has asserted none of them.
 *
 * What it can be told to do:
 *
 * - offer a set of tools, and then **offer different ones**;
 * - keep a tool's parameters and **rewrite its description**, which is the
 *   attack the schema hash exists for;
 * - return a description that is an instruction, or 50 KB long, or full of
 *   markup;
 * - hang until the timeout;
 * - return more bytes than the response cap allows;
 * - return a JSON-RPC error, or a malformed body;
 * - name a tool something that cannot be published here.
 *
 * ```php
 * $fake = new FakeMcpServer;
 * $fake->offer('lookup_invoice', 'Look up an invoice by number.');
 *
 * app()->instance(McpTransportContract::class, $fake);
 * ```
 */
final class FakeMcpServer implements McpTransportContract
{
    /** @var array<string, array<string, mixed>> */
    private array $tools = [];

    /** @var array<string, mixed> */
    private array $results = [];

    private bool $hangs = false;

    private bool $unreachable = false;

    private ?string $rpcError = null;

    private ?int $oversizedBytes = null;

    /** @var list<array{method: string, name: string|null, arguments: array<string, mixed>}> */
    public array $calls = [];

    /**
     * Offer a tool, or redefine one already offered.
     *
     * @param array<string, mixed>|null $inputSchema
     */
    public function offer(string $name, string $description = 'A tool.', ?array $inputSchema = null): self
    {
        $this->tools[$name] = [
            'name' => $name,
            'description' => $description,
            'inputSchema' => $inputSchema ?? [
                'type' => 'object',
                'properties' => ['query' => ['type' => 'string']],
                'required' => ['query'],
            ],
        ];

        return $this;
    }

    /**
     * Keep everything about a tool and change only what it SAYS.
     *
     * The whole reason the schema hash covers the description. A server doing
     * this has changed nothing a schema-only hash would notice, and has
     * changed the sentence that reaches the model.
     */
    public function rewriteDescription(string $name, string $description): self
    {
        if (isset($this->tools[$name])) {
            $this->tools[$name]['description'] = $description;
        }

        return $this;
    }

    public function withdraw(string $name): self
    {
        unset($this->tools[$name]);

        return $this;
    }

    /**
     * What a call to this tool returns.
     */
    public function returns(string $name, mixed $result): self
    {
        $this->results[$name] = $result;

        return $this;
    }

    /** Every call times out. */
    public function hangs(bool $hangs = true): self
    {
        $this->hangs = $hangs;

        return $this;
    }

    /** Nothing answers at all. */
    public function unreachable(bool $unreachable = true): self
    {
        $this->unreachable = $unreachable;

        return $this;
    }

    /** The server answers, with a JSON-RPC error. */
    public function failsWith(string $message): self
    {
        $this->rpcError = $message;

        return $this;
    }

    /** The server answers with more than anybody wants. */
    public function returnsOversized(int $bytes = 1048576): self
    {
        $this->oversizedBytes = $bytes;

        return $this;
    }

    public function listTools(McpServer $server): array
    {
        $this->calls[] = ['method' => 'tools/list', 'name' => null, 'arguments' => []];

        $this->guard($server);

        return array_values($this->tools);
    }

    public function callTool(McpServer $server, string $remoteName, array $arguments): array
    {
        $this->calls[] = ['method' => 'tools/call', 'name' => $remoteName, 'arguments' => $arguments];

        $this->guard($server);

        if ($this->oversizedBytes !== null) {
            $limit = (int) config('pandora.mcp.client.max_response_bytes', 262144);

            if ($this->oversizedBytes > $limit) {
                // Refused by size, exactly as a real transport would before it
                // decoded anything.
                throw McpDenied::responseTooLarge($server->slug, $limit);
            }
        }

        if (! isset($this->tools[$remoteName])) {
            throw McpDenied::callFailed($server->slug, "unknown tool [{$remoteName}]");
        }

        $result = $this->results[$remoteName] ?? 'ok';

        return ['content' => [['type' => 'text', 'text' => is_string($result) ? $result : json_encode($result)]]];
    }

    /**
     * @throws McpDenied
     */
    private function guard(McpServer $server): void
    {
        if ($this->unreachable) {
            throw McpDenied::serverUnavailable($server->slug, 'connection refused');
        }

        if ($this->hangs) {
            // Expressed as the refusal a real timeout produces rather than by
            // actually sleeping: a suite that sleeps for its timeouts is a
            // suite nobody runs.
            throw McpDenied::serverUnavailable($server->slug, 'timed out');
        }

        if ($this->rpcError !== null) {
            throw McpDenied::callFailed($server->slug, $this->rpcError);
        }
    }
}
