<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tools;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

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
        if ($directory === '' || ! is_dir($directory)) {
            return [];
        }

        $found = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = self::classIn($file->getPathname());

            if ($class === null) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Tool::class)) {
                continue;
            }

            /** @var class-string<Tool> $class */
            $found[] = $class;
        }

        sort($found);

        return $found;
    }

    /**
     * The class declared in a file, read from its namespace and class
     * statements rather than by including it — including a file to find out
     * what is in it executes whatever is in it.
     *
     * @return class-string|null
     */
    private static function classIn(string $path): ?string
    {
        $source = (string) file_get_contents($path);

        if (preg_match('/^\s*namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
            return null;
        }

        if (preg_match('/^\s*(?:final\s+|abstract\s+)*class\s+(\w+)/m', $source, $class) !== 1) {
            return null;
        }

        $fqcn = trim($namespace[1]).'\\'.$class[1];

        return class_exists($fqcn) ? $fqcn : null;
    }
}
