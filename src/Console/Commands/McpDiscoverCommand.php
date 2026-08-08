<?php

declare(strict_types=1);

namespace Pandora\Console\Commands;

use Illuminate\Console\Command;
use Pandora\Exceptions\McpDenied;
use Pandora\Mcp\Discovery;
use Pandora\Mcp\McpServer;

/**
 * Ask a server what it has, and write down what it said.
 *
 * Approves nothing, and says so in its own output — because an operator who
 * has just seen "11 tools discovered" scroll past will otherwise assume their
 * agents can use them.
 */
final class McpDiscoverCommand extends Command
{
    protected $signature = 'pandora:mcp:discover
                            {server? : Only this server, by slug}';

    protected $description = 'Discover the tools an MCP server offers (approves nothing)';

    public function handle(Discovery $discovery): int
    {
        /** @var string|null $slug */
        $slug = $this->argument('server');

        /** @var list<McpServer> $servers */
        $servers = McpServer::query()
            ->when($slug !== null, static fn ($query) => $query->where('slug', $slug))
            ->where('enabled', true)
            ->orderBy('name')
            ->get()
            ->all();

        if ($servers === []) {
            $this->components->warn('No enabled MCP server matched.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($servers as $server) {
            try {
                $result = $discovery->run($server);
            } catch (McpDenied $e) {
                $this->components->error($server->slug.': '.$e->getMessage());
                $failed = true;

                continue;
            }

            $this->components->info(sprintf(
                '%s: %d new, %d changed, %d skipped.',
                $server->slug,
                $result['discovered'],
                $result['changed'],
                $result['skipped'],
            ));

            if ($result['changed'] > 0) {
                // The interesting line. A changed tool has had its approvals
                // cleared by something the remote end did.
                $this->components->warn(
                    $result['changed'].' tool(s) changed since approval. Approval was cleared; '
                        .'they fail closed until a human approves the new version.',
                );
            }
        }

        $this->line('');
        $this->components->info('Nothing was approved. Use pandora:mcp:approve to grant a tool to an agent.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
