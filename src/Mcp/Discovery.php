<?php

declare(strict_types=1);

namespace Pandora\Mcp;

use Pandora\Audit\AuditLogger;
use Pandora\Exceptions\McpDenied;
use Pandora\Mcp\Enums\ServerHealth;
use Pandora\Mcp\Transport\TransportFactory;

/**
 * Ask a server what it has, and write down what it said.
 *
 * **Discovery approves nothing.** Every tool it writes lands unapproved, for
 * every agent, every time. There is no trusted-server flag and no first-run
 * convenience, because anything that both discovers and enables is a
 * remote-controlled permission grant: the server decides what exists and
 * therefore what is permitted, and the human is a spectator (ADR-0014).
 *
 * It is a READ OF AN UNTRUSTED SOURCE, and everything here treats it that way:
 *
 * - a tool whose name cannot be published is skipped, not sanitised — a
 *   sanitised name no longer matches what has to be sent back over the wire;
 * - a description is bounded on the way in;
 * - the namespace comes from the server's own row, never from the response;
 * - a tool that has changed since somebody approved it has its approvals
 *   cleared and says so at `warning`.
 *
 * A tool that disappears is marked unavailable rather than deleted. The row is
 * what an approval points at and what an audit entry refers to, and deleting
 * it turns "the server withdrew this" into "this never existed".
 */
final readonly class Discovery
{
    public function __construct(
        private TransportFactory $transports,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array{discovered: int, changed: int, skipped: int}
     *
     * @throws McpDenied
     */
    public function run(McpServer $server): array
    {
        try {
            $descriptors = $this->transports->for($server)->listTools($server);
        } catch (McpDenied $e) {
            $server->update([
                'health' => ServerHealth::Unhealthy->value,
                'health_message' => mb_substr($e->getMessage(), 0, 500),
                'last_probed_at' => now(),
            ]);

            $this->audit->record(
                action: 'mcp.server_unreachable',
                targetType: 'mcp_server',
                targetId: (string) $server->getKey(),
                severity: 'warning',
                metadata: ['server' => $server->slug, 'reason' => $e->reason],
            );

            throw $e;
        }

        $discovered = 0;
        $changed = 0;
        $skipped = 0;
        $seen = [];

        foreach ($descriptors as $descriptor) {
            $remoteName = is_string($descriptor['name'] ?? null) ? $descriptor['name'] : '';

            if (! Namespacing::isPublishableRemoteName($remoteName)) {
                // Skipped rather than renamed. A tool called `../../etc` or one
                // containing the separator cannot be named here, and inventing
                // a name for it would produce something that no longer matches
                // what the server has to be told on the way back.
                $skipped++;

                continue;
            }

            $namespacedName = Namespacing::qualify($server->namespace, $remoteName);
            $description = $this->boundedDescription($descriptor['description'] ?? null);
            $inputSchema = is_array($descriptor['inputSchema'] ?? null) ? $descriptor['inputSchema'] : null;

            $hash = SchemaHash::of($remoteName, $namespacedName, $description, $inputSchema);

            /** @var McpTool|null $existing */
            $existing = McpTool::query()
                ->where('server_id', $server->getKey())
                ->where('remote_name', $remoteName)
                ->first();

            if ($existing === null) {
                McpTool::query()->create([
                    'server_id' => $server->getKey(),
                    'remote_name' => $remoteName,
                    'namespaced_name' => $namespacedName,
                    'description' => $description,
                    'input_schema' => $inputSchema,
                    'schema_hash' => $hash,
                    'available' => true,
                ]);

                $discovered++;
                $seen[] = $remoteName;

                continue;
            }

            $seen[] = $remoteName;

            if (hash_equals($existing->schema_hash, $hash)) {
                $existing->update(['available' => true]);

                continue;
            }

            $this->recordChange($server, $existing, $hash, $description, $inputSchema, $namespacedName);
            $changed++;
        }

        $this->markWithdrawn($server, $seen);

        $server->update([
            'health' => ServerHealth::Healthy->value,
            'health_message' => null,
            'last_probed_at' => now(),
            'last_discovered_at' => now(),
        ]);

        $this->audit->record(
            action: 'mcp.discovery_completed',
            targetType: 'mcp_server',
            targetId: (string) $server->getKey(),
            metadata: [
                'server' => $server->slug,
                'discovered' => $discovered,
                'changed' => $changed,
                'skipped' => $skipped,
                // Said explicitly in the record, because "we found eleven
                // tools" reads like eleven new capabilities and it is zero.
                'approved' => 0,
            ],
        );

        return ['discovered' => $discovered, 'changed' => $changed, 'skipped' => $skipped];
    }

    /**
     * A tool changed after somebody approved it.
     *
     * Approvals are revoked rather than deleted, so an operator can tell "this
     * was approved and taken away by the remote end" from "nobody ever
     * approved this". The audit entry is `warning` because the actor was not
     * one of ours.
     *
     * @param array<string, mixed>|null $inputSchema
     */
    private function recordChange(
        McpServer $server,
        McpTool $tool,
        string $hash,
        ?string $description,
        ?array $inputSchema,
        string $namespacedName,
    ): void {
        $previous = $tool->schema_hash;
        $descriptionChanged = $tool->description !== $description;

        $tool->update([
            'namespaced_name' => $namespacedName,
            'description' => $description,
            'input_schema' => $inputSchema,
            'schema_hash' => $hash,
            'previous_schema_hash' => $previous,
            'schema_changed_at' => now(),
            'available' => true,
        ]);

        $revoked = McpToolApproval::query()
            ->where('mcp_tool_id', $tool->getKey())
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoked_reason' => 'schema_changed',
            ]);

        $this->audit->record(
            action: 'mcp.schema_changed',
            targetType: 'mcp_tool',
            targetId: (string) $tool->getKey(),
            severity: 'warning',
            metadata: [
                'server' => $server->slug,
                'tool' => $namespacedName,
                'previous_hash' => $previous,
                'hash' => $hash,
                // Named separately because it is the interesting case: a
                // server that kept every parameter and rewrote the sentence
                // that reaches the model.
                'description_changed' => $descriptionChanged,
                'approvals_revoked' => $revoked,
            ],
        );
    }

    /**
     * @param list<string> $seen
     */
    private function markWithdrawn(McpServer $server, array $seen): void
    {
        McpTool::query()
            ->where('server_id', $server->getKey())
            ->when($seen !== [], static fn ($query) => $query->whereNotIn('remote_name', $seen))
            ->update(['available' => false]);
    }

    private function boundedDescription(mixed $description): ?string
    {
        if (! is_string($description)) {
            return null;
        }

        $limit = (int) config('pandora.mcp.client.max_description_length', 2000);

        return mb_substr($description, 0, max(1, $limit));
    }
}
