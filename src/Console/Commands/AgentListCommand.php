<?php

declare(strict_types=1);

namespace Pandora\Console\Commands;

use Illuminate\Console\Command;
use Pandora\Agents\Agent;
use Pandora\Agents\AgentRegistry;
use Pandora\Runs\Run;

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

        // One grouped query rather than a count per row: the list is short, but
        // a command that degrades with the number of agents is a command nobody
        // runs on the installation where the answer matters.
        $runs = Run::query()
            ->selectRaw('agent_id, count(*) as aggregate')
            ->groupBy('agent_id')
            ->pluck('aggregate', 'agent_id');

        $this->table(
            ['Slug', 'Name', 'Source', 'Provider', 'Model', 'Autonomy', 'Enabled', 'Runs'],
            $all->map(static fn (Agent $agent): array => [
                $agent->slug,
                $agent->name,
                $agent->isClassDefined() ? 'class' : 'database',
                $agent->default_provider ?? '-',
                $agent->default_model ?? '-',
                $agent->autonomy_level->label(),
                $agent->enabled ? 'yes' : 'no',
                // A class-defined agent that has never been synced to a row has
                // no key to count against, and 0 is the honest answer for it.
                (string) ($runs[$agent->getKey()] ?? 0),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
