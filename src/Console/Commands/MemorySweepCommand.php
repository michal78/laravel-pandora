<?php

declare(strict_types=1);

namespace Pandora\Console\Commands;

use Illuminate\Console\Command;
use Pandora\Memory\MemoryCurator;

/**
 * Expire memories past their date, and delete their vectors.
 *
 * Housekeeping. Retrieval already excludes an expired item by predicate, so
 * this command being down for a week costs index size and nothing else --
 * which is exactly why it is safe to run on a schedule rather than on the
 * read path.
 */
final class MemorySweepCommand extends Command
{
    protected $signature = 'pandora:memory:sweep {--limit=1000 : Maximum items to expire in one pass}';

    protected $description = 'Expire memory items past their expiry date.';

    public function handle(MemoryCurator $curator): int
    {
        $expired = $curator->sweepExpired((int) $this->option('limit'));

        // Quiet when there is nothing to say: this runs on a schedule forever.
        if ($expired > 0) {
            $this->components->info(sprintf('Expired %d memory item(s).', $expired));
        }

        return self::SUCCESS;
    }
}
