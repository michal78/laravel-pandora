<?php

declare(strict_types=1);

namespace Pandora\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Pandora\Agents\AgentRegistry;
use Pandora\Automation\Automation;
use Pandora\Conversations\Conversation;
use Pandora\Pandora;
use Pandora\Providers\ProviderManager;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Run;
use Pandora\Runs\RunLock;

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
        // Three states, not two. The config flag says whether the control
        // center is WANTED; `Livewire` says whether it can exist. The provider
        // returns early without registering a single route when the class is
        // missing, so a stock install that has not required Livewire reported
        // "enabled" and answered /pandora with a 404 — the package's most
        // visual feature, silently absent, on the exact path the installer
        // tells you to open.
        $this->components->twoColumnDetail(
            '<fg=gray>Control center</>',
            match (true) {
                ! config('pandora.ui.enabled') => '<fg=yellow>headless</>',
                ! class_exists(Livewire::class) => '<fg=red>unavailable</> <fg=gray>— needs composer require livewire/livewire</>',
                default => '<fg=green>enabled</>',
            },
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
        $this->components->info('Automation');

        $enabled = Automation::query()->where('enabled', true)->count();

        $this->components->twoColumnDetail(
            '<fg=gray>Enabled automations</>',
            $enabled === 0 ? '<fg=gray>none</>' : (string) $enabled,
        );

        /** @var Automation|null $next */
        $next = Automation::query()
            ->where('enabled', true)
            ->whereNotNull('next_run_at')
            ->orderBy('next_run_at')
            ->first();

        // The two facts that answer "why hasn't anything run": whether
        // anything is scheduled, and whether the scheduler has ever been
        // heard from. A cron entry nobody added is by far the likeliest
        // cause, and it is invisible from inside the application.
        $this->components->twoColumnDetail(
            '<fg=gray>Next occurrence</>',
            $next === null || $next->next_run_at === null
                ? '<fg=gray>nothing scheduled</>'
                // In the automation's own zone, because that is the one the
                // person who configured it was thinking in.
                : $next->next_run_at->setTimezone($next->timezone)->toDateTimeString().' '.$next->timezone,
        );

        /** @var Automation|null $lastFired */
        $lastFired = Automation::query()->whereNotNull('last_run_at')->latest('last_run_at')->first();

        $this->components->twoColumnDetail(
            '<fg=gray>Last fired</>',
            $lastFired?->last_run_at?->diffForHumans()
                ?? ($enabled > 0
                    ? '<fg=yellow>never -- is `schedule:run` running every minute?</>'
                    : '<fg=gray>never</>'),
        );

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
