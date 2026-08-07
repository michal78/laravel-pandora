<?php

declare(strict_types=1);

namespace Pandora\Tests\Fixtures;

/**
 * A condition as a class rather than a closure in the config file.
 *
 * Both shapes are host code the container can resolve, and neither came from
 * a database row -- which is the only property that matters here.
 */
final class AlwaysTrueCondition
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __invoke(array $arguments): bool
    {
        return true;
    }
}
