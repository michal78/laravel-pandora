<?php

declare(strict_types=1);

namespace Pandora\Tools;

use Pandora\Support\ClassDiscovery;

/**
 * Finds tool classes under an application directory.
 *
 * Off by default. Convenience worth having, but a directory scan deciding
 * what an agent may reach is a weaker guarantee than an explicit list, and a
 * security-conscious deployment should say so out loud in config.
 */
final class ToolDiscovery
{
    /**
     * @return list<class-string<Tool>>
     */
    public static function in(string $directory): array
    {
        return ClassDiscovery::in($directory, Tool::class);
    }
}
