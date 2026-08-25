<?php

declare(strict_types=1);

namespace Pandora\Tests\Support\Concurrency;

use RuntimeException;

/**
 * A rendezvous point for N processes, so they start the work together.
 *
 * This is the part that decides whether a concurrency test is a concurrency
 * test. Booting a Laravel application takes hundreds of milliseconds and varies
 * per process; the contended section takes microseconds. Spawn five workers
 * without a barrier and the usual outcome is five sequential runs that never
 * overlap -- which passes whatever it is asked, including with the lock removed.
 *
 * The protocol is two files and no locking primitives of its own, because
 * anything cleverer would be a concurrency bug in the harness:
 *
 *   1. Each worker writes `ready-<index>` once booted, then polls for `go`.
 *   2. The parent waits for N `ready-*` files, then writes `go`.
 *
 * Polling rather than blocking: this has to work on any filesystem the CI
 * matrix offers, and a sleep of a millisecond against a barrier that is already
 * open costs a millisecond.
 */
final readonly class Barrier
{
    /**
     * The timeout covers application BOOT, not the work, so it is generous:
     * a shared CI runner starting several PHP processes at once is far slower
     * than a developer machine, and a barrier that expires during boot fails
     * the test for the runner's load rather than for anything under test.
     */
    public function __construct(
        private string $directory,
        private float $timeoutSeconds = 90.0,
    ) {}

    /**
     * Called by a worker: announce readiness, then wait for the start signal.
     */
    public function arriveAndWait(int $index): void
    {
        if (! is_dir($this->directory)) {
            throw new RuntimeException("Barrier directory {$this->directory} does not exist.");
        }

        file_put_contents($this->readyPath($index), (string) getmypid());

        $deadline = microtime(true) + $this->timeoutSeconds;

        while (! is_file($this->goPath())) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException("Worker {$index} waited {$this->timeoutSeconds}s for the start signal.");
            }

            usleep(1000);
        }
    }

    /**
     * Called by the parent: wait until every worker has booted.
     *
     * Returns false rather than throwing when a worker never arrives, because
     * the parent has the subprocess output and can report why far better than
     * an exception from here could -- a worker that died during boot is a
     * missing `ready` file AND a stderr dump worth reading.
     */
    public function waitForAll(int $count): bool
    {
        $deadline = microtime(true) + $this->timeoutSeconds;

        while (count(glob($this->directory.'/ready-*') ?: []) < $count) {
            if (microtime(true) > $deadline) {
                return false;
            }

            usleep(1000);
        }

        return true;
    }

    /**
     * Called by the parent: release every worker at once.
     */
    public function release(): void
    {
        file_put_contents($this->goPath(), 'go');
    }

    /**
     * How many workers made it to the barrier. Reported on failure, where the
     * difference between "three of five booted" and "none did" is the whole
     * diagnosis.
     */
    public function arrived(): int
    {
        return count(glob($this->directory.'/ready-*') ?: []);
    }

    private function readyPath(int $index): string
    {
        return $this->directory.'/ready-'.$index;
    }

    private function goPath(): string
    {
        return $this->directory.'/go';
    }
}
