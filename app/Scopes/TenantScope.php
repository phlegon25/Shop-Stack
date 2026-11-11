<?php

namespace App\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query on tenant-owned models to the CURRENT request's
 * tenant. The tenant comes from the server-resolved TenantContext (derived
 * from the authenticated user / impersonation session) — never from input.
 *
 * When no tenant context exists (console, seeders, super-admin processes)
 * the scope is inert; HTTP tenant routes ALWAYS establish a context via the
 * EnsureTenantContext middleware before any query can run.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(TenantContext::class)->tenantId();

        if ($tenantId !== null) {
            $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
        }
    }
}
