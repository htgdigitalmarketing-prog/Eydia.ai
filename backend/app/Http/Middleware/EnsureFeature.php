<?php

namespace App\Http\Middleware;

use App\Modules\Billing\Services\PlanLimitService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route behind a plan feature flag (e.g. feature:dash_gbp). Super admins
 * bypass all gating. Tenants whose plan does not enable the feature get a 403
 * the frontend renders as "Access Denied", so dashboard access can never be
 * reached by URL/API even if the nav card is shown.
 */
class EnsureFeature
{
    public function __construct(private PlanLimitService $plans)
    {
    }

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();

        if ($user && $user->is_super_admin) {
            return $next($request);
        }

        if (! $user || ! $user->tenant_id || ! $this->plans->feature($user->tenant_id, $feature)) {
            return response()->json([
                'success' => false,
                'message' => 'Access Denied — your plan does not include this dashboard.',
                'errors' => ['plan' => ['feature_not_included']],
            ], 403);
        }

        return $next($request);
    }
}
