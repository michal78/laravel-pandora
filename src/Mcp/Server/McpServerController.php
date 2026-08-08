<?php

declare(strict_types=1);

namespace Pandora\Mcp\Server;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pandora\Agents\Agent;
use Pandora\Audit\AuditLogger;
use Pandora\Contracts\ActorResolver;
use Pandora\Conversations\Session;
use Pandora\Core\Actor\ActorContext;
use Pandora\Core\Tenancy\TenantManager;
use Pandora\Exceptions\ToolInputInvalid;
use Pandora\Runs\Run;
use Pandora\Tools\Schema\RuleSchemaGenerator;
use Pandora\Tools\Tool;
use Pandora\Tools\ToolContext;

/**
 * Pandora as an MCP server: our tools, exposed to somebody else's agent.
 *
 * Off unless a deployment turns it on, and serving nothing unless a deployment
 * names it. Installing a package should expose nothing at all (ADR-0014).
 *
 * The property this class exists for is that **a valid token is not an
 * authorization**. Two checks, in order, and they answer different questions:
 *
 *   1. is this tool exposed?              — the allowlist, in configuration
 *   2. may THIS ACTOR call it?            — the tool's own authorize(), against
 *                                           the actor behind the token
 *
 * Skipping the second is the failure worth naming: it makes the token a
 * superuser, because the only thing it would then prove is that somebody at
 * some point was issued one. A token belonging to a support user must not
 * reach a tool that support user could not reach in the UI.
 *
 * Tools only. No resources, no prompts, and above all no sampling — a remote
 * server asking us to spend a model call on its behalf is a budget hole with a
 * protocol around it.
 */
final readonly class McpServerController
{
    public function __construct(
        private Exposure $exposure,
        private ActorResolver $actors,
        private TenantManager $tenants,
        private AuditLogger $audit,
        private RuleSchemaGenerator $schemas,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->exposure->enabled()) {
            // 404 rather than 403: a server that is off should not confirm it
            // exists and could be turned on.
            abort(404);
        }

        /** @var array<string, mixed> $payload */
        $payload = (array) $request->json()->all();
        $id = $payload['id'] ?? null;
        $method = is_string($payload['method'] ?? null) ? $payload['method'] : '';

        return match ($method) {
            'tools/list' => $this->listTools($id),
            'tools/call' => $this->callTool($payload, $id),
            default => $this->error($id, -32601, 'Method not found. This server exposes tools only.'),
        };
    }

    private function listTools(mixed $id): JsonResponse
    {
        $tools = [];

        foreach ($this->exposure->tools() as $tool) {
            // Listed if exposed, regardless of who is asking. What a caller
            // may CALL is decided per call, against the actor, and a listing
            // narrowed per actor would leak the shape of our permission model
            // to anybody with a token.
            $tools[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->schema($this->schemas),
            ];
        }

        return $this->result($id, ['tools' => $tools]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function callTool(array $payload, mixed $id): JsonResponse
    {
        /** @var array<string, mixed> $params */
        $params = (array) ($payload['params'] ?? []);
        $name = is_string($params['name'] ?? null) ? $params['name'] : '';

        /** @var array<string, mixed> $arguments */
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        $tool = $this->exposure->find($name);

        if ($tool === null) {
            // The same answer for "not exposed" and "does not exist". A caller
            // learns what this server serves, not what this installation has.
            return $this->error($id, -32602, 'No such tool.');
        }

        $actor = $this->actors->current();

        if ($actor === null || $actor->isSystem()) {
            // A system actor here would mean a request that authenticated as
            // nobody in particular, and every authorize() that asks "may this
            // person" would get an answer about a process instead.
            return $this->denied($id, $tool, 'no actor');
        }

        $context = $this->contextFor($tool, $actor);

        try {
            $input = $tool->validate($arguments, app(Factory::class));
        } catch (ToolInputInvalid $e) {
            return $this->error($id, -32602, $e->modelMessage());
        }

        // The second question, and the one a valid token does not answer.
        if (! $tool->authorize($input, $context)) {
            return $this->denied($id, $tool, 'not authorized');
        }

        try {
            $result = $tool->handle($input, $context);
        } catch (\Throwable $e) {
            // Most of these are one specific mistake: exposing a tool that
            // needs a RUN. `inspect_run_status` reads the run it is inside;
            // over this surface there is no run, so it reaches for state that
            // is not there. That is an operator misconfiguration and it must
            // read as one -- a stack trace to a protocol client tells the
            // caller about our internals and tells the operator nothing.
            report($e);

            $this->audit->record(
                action: 'mcp.server_call',
                targetType: 'tool',
                targetId: $tool->name(),
                severity: 'warning',
                metadata: [
                    'tool' => $tool->name(),
                    'ok' => false,
                    'error' => 'The tool failed. Tools that need a run cannot be exposed over MCP.',
                ],
            );

            return $this->error(
                $id,
                -32000,
                'That tool cannot be called over MCP. It needs a run, and this surface has none.',
            );
        }

        $this->audit->record(
            action: 'mcp.server_call',
            targetType: 'tool',
            targetId: $tool->name(),
            metadata: [
                'tool' => $tool->name(),
                // The tenant is recorded because tenancy on this surface is
                // ambient: it comes from the host's own resolution of the
                // request, never from anything the caller sent.
                'tenant_id' => $this->tenants->currentId(),
                'ok' => $result->ok,
            ],
        );

        return $this->result($id, [
            'content' => [['type' => 'text', 'text' => $result->content]],
            'isError' => ! $result->ok,
        ]);
    }

    /**
     * A context for a call that has no run behind it.
     *
     * The tool is being called by a person through a protocol rather than by
     * an agent inside a loop, so there is no run, no session and no agent.
     * Tools that need one say so through `authorize()` and are refused here,
     * which is the correct outcome: `delegate_to_agent` over MCP would be a
     * way to start a run from outside every budget that governs runs.
     */
    private function contextFor(Tool $tool, ActorContext $actor): ToolContext
    {
        return new ToolContext(
            run: new Run,
            agent: new Agent,
            session: new Session,
            actor: $actor,
            toolCallId: 'mcp_'.bin2hex(random_bytes(6)),
        );
    }

    private function denied(mixed $id, Tool $tool, string $reason): JsonResponse
    {
        $this->audit->record(
            action: 'mcp.exposure_denied',
            targetType: 'tool',
            targetId: $tool->name(),
            severity: 'warning',
            metadata: ['tool' => $tool->name(), 'reason' => $reason],
        );

        return $this->error($id, -32000, 'Not authorized to call this tool.');
    }

    /**
     * @param array<string, mixed> $result
     */
    private function result(mixed $id, array $result): JsonResponse
    {
        return new JsonResponse(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function error(mixed $id, int $code, string $message): JsonResponse
    {
        return new JsonResponse([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}
