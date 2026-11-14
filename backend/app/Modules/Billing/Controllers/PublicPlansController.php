<?php

namespace App\Modules\Billing\Controllers;

use App\Http\Controllers\Controller;
use App\Models\InvoiceSetting;
use App\Models\Plan;
use App\Support\BillingCycle;
use Illuminate\Http\JsonResponse;

/**
 * Public, unauthenticated view of the active plans — used by the marketing
 * landing page to show pricing. Exposes only safe, display-facing fields.
 */
class PublicPlansController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = Plan::where('is_active', true)
            ->where('is_public', true)
            ->orderBy('price')
            ->orderBy('sort_order')
            ->get();

        return $this->ok([
            'plans' => $plans->map(fn (Plan $p) => [
                'id' => $p->uuid ?? (string) $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'billing_period' => $p->billing_period,
                'price' => (float) $p->price,
                'currency' => $p->currency ?: 'INR',
                'trial_days' => $p->trial_days,
                // Keyed feature map (e.g. {"dash_gbp":true,...}) so the pricing
                // page can label each enabled capability.
                'features' => (object) ($p->features ?? []),
                'limits' => $p->limits ?? [],
                // Backend-managed per-cycle price cards (list/discounted/per-month).
                'cycle_prices' => BillingCycle::displayFor((float) $p->price),
            ])->values(),
            'offer' => BillingCycle::offerInfo(),
            'gst_rate' => (int) InvoiceSetting::current()->gst_rate,
        ]);
    }
}
