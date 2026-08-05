<?php

declare(strict_types=1);

namespace Pandora\Pandora\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Connection;

/**
 * Delete Pandora's activity: conversations, runs, traces, tool executions,
 * approvals and usage.
 *
 * For clearing out a development database, or a staging one after a demo. It
 * is deliberately narrow by default: what it removes is what an agent DID,
 * not what the deployment IS. Agents, credentials, the model catalog and
 * settings survive, because losing them turns "clear the chats" into "set the
 * whole thing up again".
 *
 * Deletes go through the query builder rather than Eloquent on purpose. Run
 * steps, audit entries and usage records are immutable at the model layer --
 * that is the point of them -- and this is the dedicated prune path their
 * docblocks refer to.
 */
final class FlushCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'pandora:flush
                            {--audit : Also delete the audit log}
                            {--all : Also delete agents, credentials, the model catalog, health and settings}
                            {--tenant= : Only this tenant\'s data}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Delete Pandora conversations, runs and usage';

    /**
     * Activity: what an agent did. Ordered child-first, so a run that is
     * interrupted half way leaves no rows pointing at something gone.
     *
     * @var list<string>
     */
    private const ACTIVITY = [
        'run_steps',
        'automation_runs',
        'webhook_deliveries',
        'observations',
        'tool_executions',
        'approvals',
        'usage_records',
        'messages',
        'runs',
        'conversation_participants',
        'conversations',
        'sessions',
    ];

    /**
     * Configuration: what the deployment IS. Only with `--all`.
     *
     * @var list<string>
     */
    private const CONFIGURATION = [
        'provider_health',
        'models',
        'provider_credentials',
        'settings',
        // Before agents: an automation binds to one, and leaving orphaned
        // automations behind would mean every occurrence refused forever.
        'automations',
        'agents',
    ];

    public function handle(Connection $connection): int
    {
        /** @var string|null $tenant */
        $tenant = $this->option('tenant');

        $tables = self::ACTIVITY;

        if ($this->option('audit') === true || $this->option('all') === true) {
            $tables[] = 'audit_logs';
        }

        if ($this->option('all') === true) {
            $tables = [...$tables, ...self::CONFIGURATION];
        }

        $this->warnAboutScope($tenant);

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $deleted = 0;

        foreach ($tables as $table) {
            $name = $this->tableName($table);

            if (! $connection->getSchemaBuilder()->hasTable($name)) {
                continue;
            }

            $query = $connection->table($name);

            if ($tenant !== null && $connection->getSchemaBuilder()->hasColumn($name, 'tenant_id')) {
                $query->where('tenant_id', $tenant);
            }

            $rows = $query->delete();
            $deleted += $rows;

            $this->components->twoColumnDetail($name, $rows === 0 ? '<fg=gray>empty</>' : "{$rows} row(s)");
        }

        $this->components->info(sprintf('Deleted %s row(s).', number_format($deleted)));

        if ($this->option('all') !== true) {
            $this->components->twoColumnDetail(
                '<fg=gray>Kept</>',
                '<fg=gray>agents, automations, credentials, models, settings</>',
            );
        }

        return self::SUCCESS;
    }

    private function warnAboutScope(?string $tenant): void
    {
        $scope = $tenant === null ? 'every tenant' : "tenant [{$tenant}]";

        $this->components->warn($this->option('all') === true
            ? "This deletes ALL Pandora data for {$scope}, including agents and credentials."
            : "This deletes Pandora conversations, runs and usage for {$scope}.");
    }

    private function tableName(string $table): string
    {
        /** @var string $prefix */
        $prefix = config('pandora.database.table_prefix', 'pandora_');

        return $prefix.$table;
    }
}
