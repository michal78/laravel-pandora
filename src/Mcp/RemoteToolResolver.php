<?php

declare(strict_types=1);

namespace Pandora\Mcp;

use Pandora\Agents\Agent;
use Pandora\Audit\AuditLogger;
use Pandora\Exceptions\McpDenied;

/**
 * Which remote tools an agent may actually use, right now.
 *
 * This is the remote half of tool resolution and it is deliberately a separate
 * object from `ToolRegistry`. Resolution is separated by ORIGIN: the core
 * registry is never asked about a namespaced name and this is never asked
 * about a core one, so a remote tool cannot be resolved where a core tool is
 * expected whatever it has named itself (ADR-0014). A single registry holding
 * both, distinguished by string prefix, is one normalisation bug away from
 * losing that — and the strings being normalised are attacker-controlled.
 *
 * Four things must all be true, and each is a different failure:
 *
 * 1. the tool exists and the server still offers it;
 * 2. the server is enabled and not known-unhealthy;
 * 3. this agent has a live approval for it;
 * 4. that approval is of the hash the tool has NOW.
 *
 * The fourth is the one that does the work. An approval is of a specific
 * description and a specific schema, so a server that rewrote either has
 * un-approved itself and the tool fails closed until a human looks.
 */
final readonly class RemoteToolResolver
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Every remote tool this agent may be offered.
     *
     * @return list<RemoteTool>
     */
    public function available(Agent $agent): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $tools = [];

        /** @var list<McpToolApproval> $approvals */
        $approvals = McpToolApproval::query()
            ->where('agent_id', $agent->getKey())
            ->whereNull('revoked_at')
            ->get()
            ->all();

        foreach ($approvals as $approval) {
            /** @var McpTool|null $tool */
            $tool = McpTool::query()->find($approval->mcp_tool_id);

            if ($tool === null || ! $tool->available || ! $approval->covers($tool)) {
                continue;
            }

            /** @var McpServer|null $server */
            $server = McpServer::query()->find($tool->server_id);

            if ($server === null || ! $server->isUsable()) {
                // An unhealthy server's tools are unavailable rather than
                // slow: a run that waits on a server known to be down has
                // converted a clear failure into a timeout.
                continue;
            }

            $tools[] = new RemoteTool($tool, $server);
        }

        return $tools;
    }

    /**
     * Resolve one namespaced name for one agent, or say why not.
     *
     * @throws McpDenied
     */
    public function resolve(string $namespacedName, Agent $agent): RemoteTool
    {
        if (! $this->enabled()) {
            throw McpDenied::notApproved($namespacedName, $agent->slug);
        }

        /** @var McpTool|null $tool */
        $tool = McpTool::query()->where('namespaced_name', $namespacedName)->first();

        if ($tool === null) {
            throw McpDenied::notApproved($namespacedName, $agent->slug);
        }

        /** @var McpToolApproval|null $approval */
        $approval = McpToolApproval::query()
            ->where('agent_id', $agent->getKey())
            ->where('mcp_tool_id', $tool->getKey())
            ->whereNull('revoked_at')
            ->first();

        if ($approval === null) {
            throw McpDenied::notApproved($namespacedName, $agent->slug);
        }

        if (! $approval->covers($tool)) {
            // Separated from "not approved" on purpose: the two ask different
            // things of an operator. One is "nobody has approved this yet";
            // the other is "what you approved is not what is there now".
            $this->audit->record(
                action: 'mcp.call_failed',
                targetType: 'mcp_tool',
                targetId: (string) $tool->getKey(),
                severity: 'warning',
                metadata: [
                    'tool' => $namespacedName,
                    'agent' => $agent->slug,
                    'reason' => 'schema_changed',
                ],
            );

            throw McpDenied::schemaChanged($namespacedName);
        }

        /** @var McpServer|null $server */
        $server = McpServer::query()->find($tool->server_id);

        if ($server === null || ! $server->isUsable()) {
            throw McpDenied::serverUnavailable($server?->slug ?? 'unknown');
        }

        if (! $tool->available) {
            throw McpDenied::serverUnavailable($server->slug, 'the server no longer offers this tool');
        }

        return new RemoteTool($tool, $server);
    }

    private function enabled(): bool
    {
        return (bool) config('pandora.mcp.client.enabled', false);
    }
}
