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
    'Delegation',
    'Mcp',
    'McpServer',
);

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
