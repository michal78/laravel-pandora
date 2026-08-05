<?php

declare(strict_types=1);

namespace Pandora\Pandora\Core\Tenancy\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Pandora\Pandora\Core\Tenancy\TenantManager;

/**
 * Scopes a model to the current tenant and stamps `tenant_id` on create.
 *
 * With the default null resolver both behaviours are inert, so single-tenant
 * applications pay nothing. When a tenant IS resolved the scope is applied
 * unconditionally -- including to direct `find()` calls, which is where
 * cross-tenant leaks usually come from.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('pandora_tenant', function (Builder $query): void {
            $tenantId = app(TenantManager::class)->currentId();

            if ($tenantId !== null) {
                $query->where($query->getModel()->getTable().'.tenant_id', $tenantId);
            }
        });

        static::creating(function (self $model): void {
            if ($model->tenant_id === null) {
                $model->tenant_id = app(TenantManager::class)->currentId();
            }
        });
    }

    /**
     * Escape the tenant scope. Every call site is a potential cross-tenant
     * leak, so this is deliberately verbose and greppable.
     */
    public static function acrossAllTenants(): Builder
    {
        return static::query()->withoutGlobalScope('pandora_tenant');
    }
}
