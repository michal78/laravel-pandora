<?php

declare(strict_types=1);

namespace Pandora\Tests\Support\Concurrency\Scenarios;

use Pandora\Runs\RunLock;
use Pandora\Tests\Support\Concurrency\Scenario;

/**
 * Every worker tries to take ownership of the same run at the same instant.
 *
 * Exactly one may succeed. The cache half of `RunLock` cannot arbitrate this at
 * all -- the array store is per-process, so all N acquire their own private
 * cache lock and agree -- which is what makes this a clean test of the database
 * lease that the class documents as "the authority".
 */
final readonly class AcquireRunLock implements Scenario
{
    public function __construct(private RunLock $locks) {}

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function run(array $payload): array
    {
        $runId = (string) $payload['run_id'];

        $startedAt = microtime(true);
        $token = $this->locks->acquire($runId);
        $finishedAt = microtime(true);

        return [
            'acquired' => $token !== null,
            'token' => $token,
            // Timestamps come back so a test can assert the workers actually
            // OVERLAPPED. Without that, "exactly one acquired" is also what
            // five sequential runs produce, and the harness would be proving
            // nothing while looking convincing.
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'pid' => getmypid(),
        ];
    }
}
