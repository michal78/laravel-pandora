<?php

declare(strict_types=1);

namespace Pandora\Tests\Support;

use Pandora\Tests\Support\Concurrency\Barrier;
use Pandora\Tests\Support\Concurrency\ConcurrentResult;
use Pandora\Tests\Support\Concurrency\Scenario;
use Pandora\Tests\Support\Concurrency\TestDatabase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs a scenario in N real OS processes against one database, at once.
 *
 * Phase 9 kept finding the same thing: a control that only exists between
 * processes, tested by a suite that has one. `QUEUE_CONNECTION=sync` and a
 * serial runner disarmed the approval row lock (T14), the fan-in row lock
 * (T12) and the queued-job tenant carry (T2), and in each case the test went
 * green with the control deleted. `ExactlyOnceUnderLockTest` answered that with
 * a second connection, which is right for two contenders and does not scale to
 * fifty.
 *
 * This is the general form. It is deliberately NOT `pest --parallel`: that
 * distributes whole test files across workers to run the suite faster, and it
 * would collide head-on with the shared-schema optimisation in `TestCase`
 * (see `$serverSchemaReady`). What is needed is concurrency INSIDE one test,
 * with the schema already migrated and left alone.
 *
 * @see Barrier for why the synchronised start is the load-bearing part
 */
trait RunsConcurrently
{
    /**
     * Skip unless this leg can actually host contention.
     *
     * Called by a test rather than automatically, so that a file mixing
     * concurrent and ordinary tests keeps the ordinary ones running everywhere.
     */
    protected function requiresConcurrency(): void
    {
        if (! TestDatabase::supportsConcurrency()) {
            $this->markTestSkipped(
                'Concurrency needs a shared server engine; the SQLite leg gives every connection its own :memory: database. The matrix runs these legs.',
            );
        }
    }

    /**
     * Run `$scenario` in `$processes` subprocesses simultaneously.
     *
     * @param class-string<Scenario> $scenario
     * @param array<string, mixed> $payload
     */
    protected function concurrently(string $scenario, int $processes, array $payload = [], float $timeout = 120.0): ConcurrentResult
    {
        $directory = sys_get_temp_dir().'/pandora-barrier-'.bin2hex(random_bytes(8));
        mkdir($directory, 0777, true);

        $barrier = new Barrier($directory);
        $worker = __DIR__.'/Concurrency/worker.php';
        $environment = TestDatabase::workerEnvironment();
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

        /** @var list<Process> $running */
        $running = [];

        try {
            for ($index = 0; $index < $processes; $index++) {
                $process = new Process(
                    [PHP_BINARY, $worker, $directory, (string) $index, $scenario, $payloadJson],
                    env: $environment,
                    timeout: $timeout,
                );

                $process->start();
                $running[] = $process;
            }

            if (! $barrier->waitForAll($processes)) {
                throw new RuntimeException(sprintf(
                    "Only %d of %d workers reached the barrier. First worker stderr:\n%s",
                    $barrier->arrived(),
                    $processes,
                    $running[0]->getErrorOutput() ?: '(empty)',
                ));
            }

            $barrier->release();

            foreach ($running as $process) {
                $process->wait();
            }

            return ConcurrentResult::fromProcesses($running);
        } finally {
            foreach ($running as $process) {
                $process->isRunning() && $process->stop();
            }

            $this->removeDirectory($directory);
        }
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }

        is_dir($directory) && rmdir($directory);
    }
}
