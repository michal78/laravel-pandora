<?php

declare(strict_types=1);

namespace Pandora\Console\Commands;

use Illuminate\Console\Command;
use Pandora\Agents\Agent;
use Pandora\Audit\AuditLogger;
use Pandora\Mcp\McpTool;
use Pandora\Mcp\McpToolApproval;
use Pandora\Mcp\SchemaHash;

/**
 * Let one agent call one remote tool, as it reads right now.
 *
 * The `--hash` option is the point of this command existing as a command. An
 * operator approving from a terminal has usually just run `discover`, and the
 * thing they are approving may have changed between the two. Passing the hash
 * they were shown makes the approval refuse rather than silently approve
 * something else — the same reason a package manager prints a checksum.
 *
 * Without `--hash` it approves what is there now and prints it, which is
 * honest for the ordinary case and still leaves a record of exactly what was
 * agreed to.
 */
final class McpApproveCommand extends Command
{
    protected $signature = 'pandora:mcp:approve
                            {tool : The namespaced tool name, e.g. ledger.lookup_invoice}
                            {agent : The agent slug}
                            {--hash= : The schema hash you were shown; refuses if it has moved}
                            {--revoke : Take the approval away instead}';

    protected $description = 'Approve a remote MCP tool for one agent';

    public function handle(AuditLogger $audit): int
    {
        /** @var string $toolName */
        $toolName = $this->argument('tool');
        /** @var string $agentSlug */
        $agentSlug = $this->argument('agent');

        /** @var McpTool|null $tool */
        $tool = McpTool::query()->where('namespaced_name', $toolName)->first();

        if ($tool === null) {
            $this->components->error("No discovered MCP tool is named [{$toolName}]. Run pandora:mcp:discover first.");

            return self::FAILURE;
        }

        /** @var Agent|null $agent */
        $agent = Agent::query()->where('slug', $agentSlug)->first();

        if ($agent === null) {
            $this->components->error("No agent is named [{$agentSlug}].");

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            return $this->revoke($tool, $agent, $audit);
        }

        // Re-derived from the row rather than trusted, so an approval cannot
        // be granted against a stale stored hash.
        $current = SchemaHash::ofTool($tool);

        /** @var string|null $expected */
        $expected = $this->option('hash');

        if ($expected !== null && ! hash_equals($current, $expected)) {
            $this->components->error(
                'This tool has changed since you were shown that hash. Nothing was approved.',
            );
            $this->components->twoColumnDetail('  you passed', $expected);
            $this->components->twoColumnDetail('  it is now', $current);

            return self::FAILURE;
        }

        if ($tool->schema_changed_at !== null && $expected === null) {
            // Not a refusal: an operator may well be approving BECAUSE it
            // changed. But they should know before they do.
            $this->components->warn(
                'This tool changed at '.$tool->schema_changed_at->toDateTimeString()
                    .'. You are approving the new version.',
            );
        }

        McpToolApproval::query()->updateOrCreate(
            ['agent_id' => $agent->getKey(), 'mcp_tool_id' => $tool->getKey()],
            [
                'approved_schema_hash' => $current,
                'approved_at' => now(),
                'revoked_at' => null,
                'revoked_reason' => null,
            ],
        );

        $audit->record(
            action: 'mcp.tool_approved',
            targetType: 'mcp_tool',
            targetId: (string) $tool->getKey(),
            metadata: ['tool' => $tool->namespaced_name, 'agent' => $agent->slug, 'hash' => $current],
        );

        $this->components->info("[{$tool->namespaced_name}] approved for [{$agent->slug}].");
        $this->components->twoColumnDetail('  hash', $current);

        return self::SUCCESS;
    }

    private function revoke(McpTool $tool, Agent $agent, AuditLogger $audit): int
    {
        $updated = McpToolApproval::query()
            ->where('agent_id', $agent->getKey())
            ->where('mcp_tool_id', $tool->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => 'operator']);

        if ($updated === 0) {
            $this->components->warn('That tool was not approved for that agent.');

            return self::SUCCESS;
        }

        $audit->record(
            action: 'mcp.tool_revoked',
            targetType: 'mcp_tool',
            targetId: (string) $tool->getKey(),
            severity: 'warning',
            metadata: ['tool' => $tool->namespaced_name, 'agent' => $agent->slug],
        );

        $this->components->info("[{$tool->namespaced_name}] revoked for [{$agent->slug}].");

        return self::SUCCESS;
    }
}
