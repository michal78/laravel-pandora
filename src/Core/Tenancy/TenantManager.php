<?php

declare(strict_types=1);

namespace Pandora\Core\Tenancy;

use Pandora\Contracts\TenantResolver;

/**
 * Holds the tenant for the current process.
 *
 * Queued jobs carry their tenant explicitly and re-enter it through `with()`,
 * because the request the tenant was originally resolved from is long gone by
 * the time a worker picks the job up.
 */
final class TenantManager implements TenantResolver
{
    private ?TenantContext $override = null;

    private bool $overridden = false;

    public function __construct(
        private readonly TenantResolver $resolver,
    ) {}

    public function current(): ?TenantContext
    {
        return $this->overridden ? $this->override : $this->resolver->current();
    }

    public function currentId(): ?string
    {
        return $this->current()?->id;
    }

    public function with(?TenantContext $tenant, \Closure $callback): mixed
    {
        $previousOverride = $this->override;
        $previouslyOverridden = $this->overridden;

        $this->override = $tenant;
        $this->overridden = true;

        try {
            return $callback();
        } finally {
            $this->override = $previousOverride;
            $this->overridden = $previouslyOverridden;
        }
    }
}
