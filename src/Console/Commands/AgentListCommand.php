<?php

declare(strict_types=1);

namespace Pandora\Console\Commands;

use Illuminate\Console\Command;
use Pandora\Agents\Agent;
use Pandora\Agents\AgentRegistry;

final class AgentListCommand extends Command
{
    protected $signature = 'pandora:agent:list';

    protected $description = 'List every registered Pandora agent.';

    public function handle(AgentRegistry $agents): int
    {
        $all = $agents->all();

        if ($all->isEmpty()) {
            $this->components->warn('No agents registered.');
            $this->line('  Add an AgentDefinition class to `pandora.agents.definitions` in config/pandora.php.');

            return self::SUCCESS;
        }

        $this->table(
            ['Slug', 'Name', 'Source', 'Provider', 'Model', 'Autonomy', 'Enabled'],
            $all->map(static fn (Agent $agent): array => [
                $agent->slug,
                $agent->name,
                $agent->isClassDefined() ? 'class' : 'database',
                $agent->default_provider ?? '-',
                $agent->default_model ?? '-',
                $agent->autonomy_level->label(),
                $agent->enabled ? 'yes' : 'no',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
