<?php

namespace App\Support\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope that constrains every query on a tenant-owned model to the
 * authenticated user's tenant. Tenant id is ALWAYS derived from auth context,
 * never from request input. Super admins (tenant_id null) are handled by an
 * explicit, audited admin guard that removes this scope deliberately.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        if ($user === null || $user->tenant_id === null) {
            return; // unauthenticated context or super admin; handled explicitly elsewhere
        }

        $builder->where($model->getTable() . '.tenant_id', $user->tenant_id);
    }
}
