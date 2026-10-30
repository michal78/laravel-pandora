<?php

declare(strict_types=1);

namespace Pandora\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Finds concrete classes of one type under an application directory.
 *
 * Behind tool and agent discovery, both off by default: a directory scan
 * deciding what exists is a weaker guarantee than an explicit list, and a
 * deployment that wants it should say so out loud in config.
 */
final class ClassDiscovery
{
    /**
     * @template T of object
     *
     * @param class-string<T> $type a class to extend or an interface to implement
     * @return list<class-string<T>>
     */
    public static function in(string $directory, string $type): array
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

            if (! $reflection->isInstantiable() || ! $reflection->isSubclassOf($type)) {
                continue;
            }

            /** @var class-string<T> $class */
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
