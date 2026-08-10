<?php

declare(strict_types=1);

namespace Pandora\UI\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Pandora\Agents\Agent;
use Pandora\Audit\AuditLogger;
use Pandora\Exceptions\McpDenied;
use Pandora\Mcp\Discovery;
use Pandora\Mcp\McpServer;
use Pandora\Mcp\McpTool;
use Pandora\Mcp\McpToolApproval;
use Pandora\Mcp\SchemaHash;
use Pandora\UI\PandoraGate;

/**
 * The servers Pandora talks to, and what it has been allowed to call.
 *
 * The page is arranged around one question — *what changed?* — because that is
 * the question this phase exists to make answerable. A tool whose hash moved
 * since somebody approved it is the loudest thing on the page, and approving
 * it again is a deliberate act with the description in front of you.
 *
 * Everything a server said is rendered as escaped text. Blade escapes by
 * default and nothing here reaches for the raw form; a test asserts that,
 * because this is the one page whose content was written by a stranger.
 */
final class McpIndex extends Component
{
    #[Url(as: 'server', except: '')]
    public string $selected = '';

    public ?string $notice = null;

    public ?string $error = null;

    /**
     * The agent chosen in each tool row's approve control, by tool id.
     *
     * @var array<string, string>
     */
    public array $approveFor = [];

    public function mount(): void
    {
        PandoraGate::authorize('access');
    }

    public function select(string $slug): void
    {
        $this->selected = $slug;
        $this->notice = null;
        $this->error = null;
    }

    /**
     * Ask the server what it has now.
     *
     * Synchronous from this page on purpose: an operator who pressed the
     * button is waiting for the answer, and the interesting outcome — a tool
     * changed and its approvals were cleared — is one they should see now
     * rather than find in a log later.
     */
    public function discover(Discovery $discovery): void
    {
        PandoraGate::authorize('mcp.manage');

        $server = $this->server();

        if ($server === null) {
            return;
        }

        try {
            $result = $discovery->run($server);
        } catch (McpDenied $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->error = null;
        $this->notice = sprintf(
            '%d new, %d changed, %d skipped. Nothing was approved.',
            $result['discovered'],
            $result['changed'],
            $result['skipped'],
        );
    }

    /**
     * Approve one tool for one agent, from the page that showed the diff.
     *
     * The page exists to make a change reviewable, and a review with no way to
     * act on it sends an operator who has just read the diff to a terminal to
     * retype what they were looking at.
     *
     * The hash is re-derived here rather than carried from the request, for
     * the same reason `pandora:mcp:approve` re-derives it: an approval is of
     * the version that is there NOW, and a hash that travelled through the
     * browser is a hash the browser could have chosen.
     */
    public function approve(string $toolId, AuditLogger $audit): void
    {
        PandoraGate::authorize('mcp.manage');

        $this->notice = null;
        $this->error = null;

        /** @var McpTool|null $tool */
        $tool = McpTool::query()->find($toolId);

        if ($tool === null) {
            return;
        }

        $agentId = $this->approveFor[$toolId] ?? '';

        /** @var Agent|null $agent */
        $agent = $agentId === '' ? null : Agent::query()->find($agentId);

        if ($agent === null) {
            $this->error = 'Choose an agent to approve this tool for.';

            return;
        }

        $hash = SchemaHash::ofTool($tool);

        McpToolApproval::query()->updateOrCreate(
            ['agent_id' => $agent->getKey(), 'mcp_tool_id' => $tool->getKey()],
            [
                'approved_schema_hash' => $hash,
                'approved_at' => now(),
                'revoked_at' => null,
                'revoked_reason' => null,
            ],
        );

        $audit->record(
            action: 'mcp.tool_approved',
            targetType: 'mcp_tool',
            targetId: (string) $tool->getKey(),
            metadata: ['tool' => $tool->namespaced_name, 'agent' => $agent->slug, 'hash' => $hash],
        );

        $this->approveFor[$toolId] = '';
        $this->notice = "[{$tool->namespaced_name}] approved for [{$agent->slug}].";
    }

    public function revoke(string $toolId, string $agentId, AuditLogger $audit): void
    {
        PandoraGate::authorize('mcp.manage');

        /** @var McpTool|null $tool */
        $tool = McpTool::query()->find($toolId);

        if ($tool === null) {
            return;
        }

        $updated = McpToolApproval::query()
            ->where('mcp_tool_id', $tool->getKey())
            ->where('agent_id', $agentId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => 'operator']);

        if ($updated === 0) {
            return;
        }

        $audit->record(
            action: 'mcp.tool_revoked',
            targetType: 'mcp_tool',
            targetId: (string) $tool->getKey(),
            severity: 'warning',
            metadata: ['tool' => $tool->namespaced_name, 'agent_id' => $agentId],
        );

        $this->notice = 'Revoked.';
    }

    public function render(): View
    {
        $server = $this->server();

        return view('pandora::livewire.mcp-index', [
            'servers' => $this->servers(),
            'server' => $server,
            'tools' => $server === null ? collect() : $this->toolsFor($server),
            'approvals' => $this->approvalsByTool(),
            'agentNames' => $this->agentNames(),
            'agents' => Agent::query()->orderBy('name')->get(['id', 'name', 'slug']),
            'canManage' => PandoraGate::allows('mcp.manage'),
            'clientEnabled' => (bool) config('pandora.mcp.client.enabled', false),
        ])->layout('pandora::layouts.app', ['title' => 'MCP']);
    }

    /** @return Collection<int, McpServer> */
    private function servers(): Collection
    {
        /** @var Collection<int, McpServer> $servers */
        $servers = McpServer::query()->orderBy('name')->get();

        return $servers;
    }

    private function server(): ?McpServer
    {
        if ($this->selected === '') {
            return null;
        }

        /** @var McpServer|null $server */
        $server = McpServer::query()->where('slug', $this->selected)->first();

        return $server;
    }

    /** @return Collection<int, McpTool> */
    private function toolsFor(McpServer $server): Collection
    {
        /** @var Collection<int, McpTool> $tools */
        $tools = McpTool::query()
            ->where('server_id', $server->getKey())
            ->orderBy('remote_name')
            ->get();

        return $tools;
    }

    /**
     * Live approvals, keyed by tool, so a row can say who may call it.
     *
     * @return array<string, list<McpToolApproval>>
     */
    private function approvalsByTool(): array
    {
        $grouped = [];

        /** @var list<McpToolApproval> $approvals */
        $approvals = McpToolApproval::query()->whereNull('revoked_at')->get()->all();

        foreach ($approvals as $approval) {
            $grouped[$approval->mcp_tool_id][] = $approval;
        }

        return $grouped;
    }

    /**
     * The agents named by live approvals, by id.
     *
     * A page whose job is "who may call this remote tool" that answers with a
     * ULID has not answered. The id is kept as the fallback, because an
     * approval outliving its agent is a real state and printing nothing there
     * would be worse than printing the key.
     *
     * @return array<string, string>
     */
    private function agentNames(): array
    {
        /** @var array<string, string> $names */
        $names = Agent::query()
            ->whereIn('id', McpToolApproval::query()->whereNull('revoked_at')->pluck('agent_id'))
            ->pluck('slug', 'id')
            ->all();

        return $names;
    }

    /**
     * Whether a tool is still the thing that was approved.
     *
     * Recomputed here rather than trusting `schema_changed_at`, because the
     * page's job is to tell an operator what is true now.
     */
    public function hashMoved(McpTool $tool): bool
    {
        return ! hash_equals($tool->schema_hash, SchemaHash::ofTool($tool));
    }
}
