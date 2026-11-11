<?php

namespace App\Models\Concerns;

use App\Scopes\TenantScope;
use App\Support\TenantContext;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if (empty($model->tenant_id)) {
                $tenantId = app(TenantContext::class)->tenantId();
                if ($tenantId !== null) {
                    $model->tenant_id = $tenantId; // server-derived, never client input
                }
            }
        });
    }

    /**
     * Cross-tenant query — only usable by super-admin/platform code paths.
     */
    public static function withoutTenancy(): \Illuminate\Database\Eloquent\Builder
    {
        return static::query()->withoutGlobalScope(TenantScope::class);
    }
}
