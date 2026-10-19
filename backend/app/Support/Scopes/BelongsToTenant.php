<?php

namespace App\Support\Scopes;

/**
 * Attach to any tenant-owned Eloquent model. Applies TenantScope and
 * auto-fills tenant_id on create from the authenticated user.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        // Force tenant_id from the authenticated tenant user, overriding any value
        // supplied via input/mass-assignment. A caller can never place a row in a
        // tenant other than their own. When there is no tenant user in context
        // (super admin with tenant_id null, or an unauthenticated queue/webhook
        // job), the explicitly-set tenant_id is trusted.
        static::creating(function ($model) {
            $user = auth()->user();
            if ($user && $user->tenant_id !== null) {
                $model->tenant_id = $user->tenant_id;
            }
        });
    }
}
