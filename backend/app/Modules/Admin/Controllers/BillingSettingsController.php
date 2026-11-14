<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BillingSetting;
use App\Support\BillingCycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Super-admin pricing controls: per-cycle discount %, which cycles the pricing
 * page shows, and the limited-time launch offer (label + countdown end time).
 */
class BillingSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        return $this->ok(['settings' => $this->toArray(BillingSetting::current())]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $data = $request->validate([
            'cycle_discounts' => ['sometimes', 'array'],
            'cycle_discounts.*' => ['numeric', 'min:0', 'max:100'],
            'enabled_cycles' => ['sometimes', 'array'],
            'enabled_cycles.*' => ['string'],
            'offer_label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'offer_ends_at' => ['sometimes', 'nullable', 'date'],
            'offer_active' => ['sometimes', 'boolean'],
        ]);

        // Keep only real cycles in the enabled list + discount map.
        if (isset($data['enabled_cycles'])) {
            $data['enabled_cycles'] = array_values(array_filter($data['enabled_cycles'], fn ($c) => BillingCycle::valid($c)));
        }
        if (isset($data['cycle_discounts'])) {
            $data['cycle_discounts'] = array_filter(
                $data['cycle_discounts'],
                fn ($v, $k) => BillingCycle::valid($k),
                ARRAY_FILTER_USE_BOTH,
            );
        }

        $settings = BillingSetting::current();
        $settings->update($data);

        return $this->ok(['settings' => $this->toArray($settings->fresh()), 'message' => 'Pricing settings saved.']);
    }

    private function toArray(BillingSetting $s): array
    {
        return [
            'cycle_discounts' => $s->cycle_discounts ?: BillingCycle::defaultDiscounts(),
            'enabled_cycles' => $s->cyclesToShow(),
            'offer_label' => $s->offer_label,
            'offer_ends_at' => $s->offer_ends_at?->toIso8601String(),
            'offer_active' => (bool) $s->offer_active,
            // Every cycle the system supports (so the admin UI can list them all).
            'available_cycles' => collect(BillingCycle::CYCLES)
                ->map(fn ($c, $k) => ['key' => $k, 'label' => $c['label'], 'months' => $c['months']])
                ->values(),
        ];
    }

    private function ensureSuperAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_super_admin, 403, 'Super admin only.');
    }
}
