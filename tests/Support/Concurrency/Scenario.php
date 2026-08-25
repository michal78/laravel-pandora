<?php

declare(strict_types=1);

namespace Pandora\Tests\Support\Concurrency;

/**
 * Work that runs inside a concurrent worker subprocess.
 *
 * A scenario is a class rather than a closure because closures do not cross a
 * process boundary. It is handed the fully booted application and whatever
 * payload the test passed, and returns a JSON-encodable array the parent
 * collects.
 *
 * A scenario must not assume it is alone, must not migrate, and must not empty
 * a table: N copies of it are running against one database at the same instant,
 * and the harness's whole purpose is that they interfere if the code under test
 * lets them.
 */
interface Scenario
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function run(array $payload): array;
}
