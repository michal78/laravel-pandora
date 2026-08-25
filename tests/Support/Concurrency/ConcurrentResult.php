<?php

declare(strict_types=1);

namespace Pandora\Tests\Support\Concurrency;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * What N workers came back with.
 *
 * The distinction this type exists to keep is between a worker that FAILED and
 * a worker that was REFUSED. A test of a lock expects most of its workers to be
 * turned away -- that is the control working -- and a harness that treated a
 * refusal as an error would report success as failure. So a non-zero exit or an
 * unparseable line is a harness problem and throws; what the scenario chose to
 * report is data.
 */
final readonly class ConcurrentResult
{
    /**
     * @param list<array<string, mixed>> $results
     */
    private function __construct(public array $results) {}

    /**
     * @param list<Process> $processes
     */
    public static function fromProcesses(array $processes): self
    {
        $results = [];

        foreach ($processes as $index => $process) {
            $output = trim($process->getOutput());
            $lines = array_values(array_filter(explode("\n", $output), static fn (string $l): bool => trim($l) !== ''));
            $last = $lines === [] ? '' : end($lines);

            if ($last === '') {
                throw new RuntimeException(sprintf(
                    "Worker %d produced no result. Exit %s.\nstderr:\n%s",
                    $index,
                    var_export($process->getExitCode(), true),
                    $process->getErrorOutput() ?: '(empty)',
                ));
            }

            try {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($last, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new RuntimeException(sprintf(
                    "Worker %d printed something that is not JSON: %s\nstderr:\n%s",
                    $index,
                    $last,
                    $process->getErrorOutput() ?: '(empty)',
                ), previous: $e);
            }

            if (($decoded['ok'] ?? false) !== true) {
                throw new RuntimeException(sprintf(
                    'Worker %d failed at the %s stage: %s (%s) at %s',
                    $index,
                    $decoded['stage'] ?? 'unknown',
                    $decoded['error'] ?? 'no message',
                    $decoded['class'] ?? 'unknown class',
                    $decoded['file'] ?? 'unknown file',
                ));
            }

            /** @var array<string, mixed> $result */
            $result = $decoded['result'] ?? [];
            $results[] = $result;
        }

        return new self($results);
    }

    /**
     * Every worker's value for one key.
     *
     * @return list<mixed>
     */
    public function pluck(string $key): array
    {
        return array_values(array_map(
            static fn (array $result): mixed => $result[$key] ?? null,
            $this->results,
        ));
    }

    /**
     * How many workers reported a truthy value for `$key` -- "how many of the
     * fifty believed they had the lock", which is the assertion almost every
     * test here wants to make.
     */
    public function countWhere(string $key): int
    {
        return count(array_filter($this->pluck($key)));
    }

    public function count(): int
    {
        return count($this->results);
    }
}
