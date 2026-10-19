<?php

namespace App\Http\Middleware;

use App\Support\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after auth:api. Guarantees the caller has a resolvable tenant context and
 * that the tenant is not suspended. Tenant is ALWAYS the authenticated user's
 * tenant — never taken from the request. Super admins (tenant_id null) pass
 * through; their access is governed by the admin guard/policies instead.
 */
class EnsureTenant
{
    use ApiResponse;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $this->fail('Unauthenticated.', [], 401);
        }

        // Super admin operates outside a single tenant.
        if ($user->is_super_admin) {
            return $next($request);
        }

        // Read the tenant fresh from the DB rather than a possibly-cached relation:
        // suspension must be enforced against current state, not a stale copy.
        $tenant = $user->tenant_id !== null ? $user->tenant()->first() : null;

        if ($tenant === null) {
            return $this->fail('No tenant is associated with this account.', [], 403);
        }

        if ($tenant->isSuspended()) {
            return $this->fail('This account is suspended. Please contact support.', [], 403);
        }

        // Make the resolved tenant available to downstream handlers.
        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }
}
