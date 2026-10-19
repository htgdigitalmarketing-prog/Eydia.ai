<?php

namespace App\Http\Middleware;

use App\Modules\Billing\Services\PlanLimitService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks every workspace feature (WhatsApp, inbox, contacts, campaigns, …)
 * until the tenant has an active plan. Billing, support and profile routes are
 * mounted outside this middleware so a tenant can still buy a plan.
 */
class EnsureActivePlan
{
    public function __construct(private readonly PlanLimitService $plans) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->is_super_admin || $user->tenant_id === null) {
            return $next($request);
        }

        if ($this->plans->planFor($user->tenant_id) === null) {
            return response()->json([
                'success' => false,
                'message' => 'You need an active plan to use this feature. Please choose or renew a plan on the Billing page.',
                'code' => 'plan_required',
            ], 403);
        }

        return $next($request);
    }
}
