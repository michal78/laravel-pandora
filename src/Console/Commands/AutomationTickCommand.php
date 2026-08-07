<?php

declare(strict_types=1);

namespace Pandora\Console\Commands;

use Illuminate\Console\Command;
use Pandora\Automation\AutomationScheduler;

/**
 * The single scheduler entry.
 *
 * Registered automatically when `pandora.automation.schedule.enabled` is true,
 * so a host adds nothing to its own Kernel. Automations live in the database
 * and change without a deploy; putting one entry per automation in a Kernel
 * would put every schedule change behind a release.
 *
 * Running two of these is safe -- that is what the occurrence claim is for --
 * but it is not useful, and you would be paying to find out.
 */
final class AutomationTickCommand extends Command
{
    protected $signature = 'pandora:automation:tick';

    protected $description = 'Claim and queue every automation that is due.';

    public function handle(AutomationScheduler $scheduler): int
    {
        $dispatched = $scheduler->tick();

        // Quiet by default. This runs every minute forever, and a line of
        // output per minute is a log nobody reads and a disk somebody fills.
        if ($dispatched !== []) {
            $this->components->info(sprintf('Queued %d automation occurrence(s).', count($dispatched)));
        }

        return self::SUCCESS;
    }
}
