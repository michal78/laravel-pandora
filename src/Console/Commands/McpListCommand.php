<?php

declare(strict_types=1);

namespace Pandora\Console\Commands;

use Illuminate\Console\Command;
use Pandora\Mcp\McpServer;
use Pandora\Mcp\McpTool;
use Pandora\Mcp\McpToolApproval;

/**
 * What remote servers this application knows, and what they offer.
 *
 * The column that matters is the last one. "Discovered" and "approved" are
 * different numbers and an operator should be able to see both at a glance:
 * eleven tools discovered and zero approved is the correct state after a
 * discovery run, and it reads as alarming until you know that.
 */
final class McpListCommand extends Command
{
    protected $signature = 'pandora:mcp:list
                            {server? : Only this server, by slug}
                            {--tools : List every tool rather than counting them}';

    protected $description = 'List the MCP servers Pandora knows about, and their tools';

    public function handle(): int
    {
        /** @var string|null $slug */
        $slug = $this->argument('server');

        /** @var list<McpServer> $servers */
        $servers = McpServer::query()
            ->when($slug !== null, static fn ($query) => $query->where('slug', $slug))
            ->orderBy('name')
            ->get()
            ->all();

        if ($servers === []) {
            $this->components->warn($slug === null
                ? 'No MCP servers are registered.'
                : "No MCP server is registered with the slug [{$slug}].");

            return self::SUCCESS;
        }

        foreach ($servers as $server) {
            $this->line('');
            $this->components->twoColumnDetail(
                '<options=bold>'.$server->name.'</> <fg=gray>'.$server->slug.'</>',
                $server->health->label().($server->enabled ? '' : ' <fg=red>(disabled)</>'),
            );
            $this->components->twoColumnDetail('  transport', $server->transport->label());
            $this->components->twoColumnDetail('  endpoint', (string) ($server->endpoint ?? $server->command ?? '—'));
            $this->components->twoColumnDetail('  namespace', $server->namespace);

            $tools = McpTool::query()->where('server_id', $server->getKey())->orderBy('remote_name')->get();

            $approved = McpToolApproval::query()
                ->whereIn('mcp_tool_id', $tools->pluck('id'))
                ->whereNull('revoked_at')
                ->count();

            $this->components->twoColumnDetail(
                '  tools',
                $tools->count().' discovered, '.$approved.' approved',
            );

            if (! $this->option('tools')) {
                continue;
            }

            foreach ($tools as $tool) {
                $live = McpToolApproval::query()
                    ->where('mcp_tool_id', $tool->getKey())
                    ->whereNull('revoked_at')
                    ->count();

                $this->components->twoColumnDetail(
                    '    '.$tool->namespaced_name.($tool->available ? '' : ' <fg=gray>(withdrawn)</>'),
                    // Plain text rather than styled: this line is the answer
                    // to "may my agent use this", and it should survive being
                    // piped, grepped and pasted into a ticket.
                    $live === 0
                        ? 'unapproved'
                        : $live.' agent(s)'.($tool->schema_changed_at === null ? '' : ', changed'),
                );
            }
        }

        $this->line('');

        return self::SUCCESS;
    }
}
