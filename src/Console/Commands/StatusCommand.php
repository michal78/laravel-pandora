<?php

declare(strict_types=1);

namespace Pandora\Pandora\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Pandora\Pandora\Agents\AgentRegistry;
use Pandora\Pandora\Conversations\Conversation;
use Pandora\Pandora\Pandora;
use Pandora\Pandora\Providers\ProviderManager;
use Pandora\Pandora\Runs\Enums\RunState;
use Pandora\Pandora\Runs\Run;
use Pandora\Pandora\Runs\RunLock;

final class StatusCommand extends Command
{
    protected $signature = 'pandora:status';

    protected $description = 'Show Pandora installation status, agents, runs and configuration.';

    public function handle(
        AgentRegistry $agents,
        ProviderManager $providers,
        RunLock $locks,
    ): int {
        $this->components->info('Pandora status');

        $prefix = (string) config('pandora.database.table_prefix', 'pandora_');

        if (! Schema::hasTable($prefix.'runs')) {
            $this->components->error('Pandora tables are missing. Run: php artisan pandora:install');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('<fg=gray>Version</>', app(Pandora::class)->version());
        $this->components->twoColumnDetail('<fg=gray>Default provider</>', (string) config('pandora.providers.default'));
        $this->components->twoColumnDetail('<fg=gray>Default model</>', (string) config('pandora.models.default'));
        $this->components->twoColumnDetail(
            '<fg=gray>Realtime</>',
            config('pandora.realtime.enabled') ? '<fg=green>enabled</>' : '<fg=yellow>polling fallback</>',
        );
        $this->components->twoColumnDetail(
            '<fg=gray>Control center</>',
            config('pandora.ui.enabled') ? '<fg=green>enabled</>' : '<fg=yellow>headless</>',
        );
        $this->components->twoColumnDetail(
            '<fg=gray>Tenancy</>',
            config('pandora.tenancy.enabled') ? 'enabled' : 'single-tenant',
        );

        $this->newLine();
        $this->components->info('Providers');

        foreach ($providers->configuredKeys() as $key) {
            $this->components->twoColumnDetail(
                $key,
                $key === $providers->default() ? '<fg=green>default</>' : '<fg=gray>configured</>',
            );
        }

        $this->newLine();
        $this->components->info('Agents');

        $all = $agents->all();

        if ($all->isEmpty()) {
            $this->components->warn('No agents registered. This is the default -- Pandora creates none for you.');
        } else {
            foreach ($all as $agent) {
                $this->components->twoColumnDetail(
                    $agent->name.' <fg=gray>('.$agent->slug.')</>',
                    $agent->enabled ? '<fg=green>enabled</>' : '<fg=yellow>disabled</>',
                );
            }
        }

        $this->newLine();
        $this->components->info('Runs');

        $counts = Run::query()
            ->selectRaw('state, count(*) as aggregate')
            ->groupBy('state')
            ->pluck('aggregate', 'state');

        if ($counts->isEmpty()) {
            $this->components->twoColumnDetail('<fg=gray>total</>', '0');
        } else {
            foreach (RunState::cases() as $state) {
                $count = (int) ($counts[$state->value] ?? 0);

                if ($count > 0) {
                    $this->components->twoColumnDetail($state->label(), (string) $count);
                }
            }
        }

        $this->components->twoColumnDetail('<fg=gray>Conversations</>', (string) Conversation::query()->count());

        $stalled = $locks->stalledRuns()->count();

        if ($stalled > 0) {
            $this->newLine();
            $this->components->warn(
                "{$stalled} run(s) appear stalled -- executing with an expired ownership lease. "
                .'A worker was probably killed mid-iteration.',
            );
        }

        return self::SUCCESS;
    }
}
