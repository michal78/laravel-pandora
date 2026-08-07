<?php

declare(strict_types=1);

namespace Pandora\Core\Tenancy;

use Pandora\Contracts\TenantResolver;

/**
 * The default: a single-tenant application.
 *
 * Every tenant scope becomes a no-op and `tenant_id` stays null. Multi-tenant
 * applications bind their own resolver; nothing else in Pandora changes.
 */
final class NullTenantResolver implements TenantResolver
{
    public function current(): ?TenantContext
    {
        return null;
    }

    public function with(?TenantContext $tenant, \Closure $callback): mixed
    {
        return $callback();
    }
}
