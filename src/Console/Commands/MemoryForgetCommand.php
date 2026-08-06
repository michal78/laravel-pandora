<?php

declare(strict_types=1);

namespace Pandora\Pandora\Console\Commands;

use Illuminate\Console\Command;
use Pandora\Pandora\Memory\MemoryCurator;
use Pandora\Pandora\Memory\MemoryItem;

/**
 * Forget one memory, from a terminal.
 *
 * Exists because "delete what you know about me" arrives as an email on a
 * Sunday, and the person who has to answer it should not need the control
 * center open to do it.
 */
final class MemoryForgetCommand extends Command
{
    protected $signature = 'pandora:memory:forget {id : The memory item id} {--reason= : Recorded in the audit log}';

    protected $description = 'Forget a memory item and delete its vector.';

    public function handle(MemoryCurator $curator): int
    {
        /** @var string $id */
        $id = $this->argument('id');

        /** @var MemoryItem|null $item */
        $item = MemoryItem::query()->find($id);

        if ($item === null) {
            $this->components->error("No memory item [{$id}].");

            return self::FAILURE;
        }

        /** @var string|null $reason */
        $reason = $this->option('reason');

        $curator->forget($item, $reason);

        $this->components->info("Forgot memory [{$id}] and deleted its vector.");

        return self::SUCCESS;
    }
}
