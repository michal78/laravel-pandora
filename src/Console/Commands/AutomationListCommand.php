<?php

declare(strict_types=1);

namespace Pandora\Pandora\Console\Commands;

use Illuminate\Console\Command;
use Pandora\Pandora\Automation\Automation;

final class AutomationListCommand extends Command
{
    protected $signature = 'pandora:automation:list {--all : Include disabled automations}';

    protected $description = 'List Pandora automations, with their schedules.';

    public function handle(): int
    {
        $query = Automation::query()->orderBy('name');

        if ($this->option('all') !== true) {
            $query->where('enabled', true);
        }

        $automations = $query->get();

        if ($automations->isEmpty()) {
            $this->components->warn('No automations.');
            $this->line('  Create one in the control center, or promote an agent observation.');

            return self::SUCCESS;
        }

        $this->table(
            ['Slug', 'Agent', 'Trigger', 'Schedule', 'Timezone', 'Autonomy', 'Next run', 'Enabled'],
            $automations->map(static fn (Automation $automation): array => [
                $automation->slug,
                $automation->agent?->slug ?? '(missing)',
                $automation->trigger_type->value,
                $automation->cron_expression
                    ?? ($automation->interval_seconds !== null ? "every {$automation->interval_seconds}s" : '-'),
                $automation->timezone,
                $automation->autonomy_level->value,
                // In the automation's own zone, because that is the one the
                // person who configured it was thinking in.
                $automation->next_run_at?->setTimezone($automation->timezone)->toDateTimeString() ?? '-',
                $automation->enabled ? 'yes' : 'no',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
