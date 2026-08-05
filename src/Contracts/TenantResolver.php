<?php

declare(strict_types=1);

namespace Pandora\Pandora\Contracts;

use Pandora\Pandora\Core\Tenancy\TenantContext;

/**
 * Resolves the tenant that owns the work currently being performed.
 *
 * Pandora bundles no tenancy package. Bind your own implementation to scope
 * every Pandora record to your application's notion of a tenant.
 *
 * Implementations MUST be safe to call outside an HTTP request: queued jobs
 * resolve the tenant from context they carry, not from a request that no
 * longer exists.
 */
interface TenantResolver
{
    public function current(): ?TenantContext;

    /**
     * Run the callback with the given tenant as the current one.
     *
     * @template TReturn
     *
     * @param \Closure(): TReturn $callback
     * @return TReturn
     */
    public function with(?TenantContext $tenant, \Closure $callback): mixed;
}
