<?php

declare(strict_types=1);

use Pandora\Core\Tenancy\TenantContext;
use Pandora\Core\Tenancy\TenantManager;
use Pandora\Tests\TestCase;

uses(TestCase::class)->in(
    'Unit',
    'Feature',
    'Security',
    'Realtime',
    'Queue',
    'Providers',
    'UI',
    'Database',
    'Tools',
    'Approvals',
    'Automation',
    'Memory',
    'Context',
    'Workspaces',
    'Skills',
    'Delegation',
    'Mcp',
    'McpServer',
    'Channels',
    'Extensions',
);

/**
 * Every PHP file under src/, as [class => path].
 *
 * Lives here rather than in the file that first needed it, because two
 * architecture files now reflect over the source tree and PHP function names
 * are global. A helper defined inside a test file exists only once that file
 * has been loaded, so the second file passed or fataled depending on the order
 * Pest happened to run them in -- which is a green suite that means nothing.
 *
 * Reflection rather than Pest's arch plugin: that plugin cannot build its file
 * index in this package's nested-vendor layout (docs/development/open-questions.md, Q1).
 *
 * @return array<class-string, string>
 */
function pandoraSourceClasses(): array
{
    static $classes = null;

    if ($classes !== null) {
        return $classes;
    }

    $root = dirname(__DIR__).'/src';
    $classes = [];

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root) + 1, -4);
        $class = 'Pandora\\'.str_replace('/', '\\', $relative);

        if (class_exists($class) || interface_exists($class) || enum_exists($class) || trait_exists($class)) {
            $classes[$class] = $file->getPathname();
        }
    }

    ksort($classes);

    return $classes;
}

/**
 * Run a callback as a given tenant.
 *
 * Shared rather than redeclared per file: three test files needed it, PHP
 * function names are global, and the third one to be written discovered that
 * the hard way.
 */
function inTenant(string $id, Closure $callback): mixed
{
    return app(TenantManager::class)
        ->with(new TenantContext($id), $callback);
}
