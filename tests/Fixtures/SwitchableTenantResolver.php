<?php

declare(strict_types=1);

namespace Pandora\Tests\Fixtures;

use Pandora\Contracts\TenantResolver;
use Pandora\Core\Tenancy\TenantContext;

/**
 * A host's tenant resolver, standing where `NullTenantResolver` normally does.
 *
 * Every other tenancy test in this suite reaches tenancy through
 * `inTenant()`, which calls `TenantManager::with()` -- the *override* path,
 * the one queued jobs use to re-enter a tenant they carry. That path is well
 * covered and it is not the path a host application uses. A host binds a
 * resolver, and `TenantManager::current()` consults it only when nothing has
 * overridden it, so `$this->resolver->current()` is a line every tenancy test
 * in the suite skips.
 *
 * Which is the Phase 6 shape exactly: a fake at a boundary makes the boundary
 * untested by construction. `NullTenantResolver` returns null unconditionally,
 * so a suite green against it cannot distinguish "the resolver was consulted
 * and answered null" from "the resolver was never consulted at all".
 *
 * This one answers, and the answer changes. It is deliberately as dumb as a
 * real one is interesting: a host resolves a tenant from a subdomain, a
 * session or a path segment, and every one of those is a value that was
 * decided before Pandora was reached. What matters here is only that Pandora
 * asks.
 */
final class SwitchableTenantResolver implements TenantResolver
{
    /**
     * Set by the test, the way a host sets it from a request.
     *
     * Static because the container holds this as a singleton and the test
     * needs to switch tenants without rebuilding it -- rebuilding would
     * discard the very binding under test.
     */
    public static ?string $tenant = null;

    public static function reset(): void
    {
        self::$tenant = null;
    }

    public function current(): ?TenantContext
    {
        return self::$tenant === null ? null : new TenantContext(self::$tenant);
    }

    public function with(?TenantContext $tenant, \Closure $callback): mixed
    {
        $previous = self::$tenant;
        self::$tenant = $tenant?->id;

        try {
            return $callback();
        } finally {
            self::$tenant = $previous;
        }
    }
}
