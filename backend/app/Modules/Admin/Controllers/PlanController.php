<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Super-admin plan management. Limits and feature flags are stored as free-form
 * JSON maps, so new limits/features can be added by the super admin at any time
 * without a code or schema change.
 */
class PlanController extends Controller
{
    /** Suggested keys the UI offers, but the super admin may add any key. */
    public const LIMIT_KEYS = ['max_agents', 'max_contacts', 'max_campaigns', 'max_chatbots', 'max_templates', 'max_locations', 'max_leads', 'max_keywords'];
    // dash_* keys control which of the three dashboards (WhatsApp, Google
    // Reviews/GBP, Lead Management) a plan unlocks. The rest are per-dashboard
    // feature toggles (incl. the ported ReviewFlow extras: seo, ads, invoicing).
    // All are free-form so new ones can be added without code.
    public const FEATURE_KEYS = ['dash_whatsapp', 'dash_gbp', 'dash_leads', 'reports', 'advanced_reports', 'export', 'social', 'chatbot', 'automations', 'api_access', 'seo', 'ads', 'invoicing'];

    public function index(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $plans = Plan::orderBy('price')->orderBy('sort_order')->get();

        return $this->ok([
            'plans' => $plans->map(fn (Plan $p) => $this->planArray($p)),
            'limit_keys' => self::LIMIT_KEYS,
            'feature_keys' => self::FEATURE_KEYS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);
        $data = $this->validatePlan($request);

        $plan = Plan::create([
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($data['name']),
            'description' => $data['description'] ?? null,
            'billing_period' => $data['billing_period'] ?? 'monthly',
            'price' => $data['price'] ?? 0,
            'currency' => $data['currency'] ?? 'INR',
            'limits' => $this->cleanLimits($data['limits'] ?? []),
            'features' => $this->cleanFeatures($data['features'] ?? []),
            'is_active' => $data['is_active'] ?? true,
            'is_public' => $data['is_public'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return $this->ok(['plan' => $this->planArray($plan)], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $this->ensureSuperAdmin($request);
        $plan = Plan::where('uuid', $uuid)->firstOrFail();
        $data = $this->validatePlan($request);

        $plan->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'billing_period' => $data['billing_period'] ?? $plan->billing_period,
            'price' => $data['price'] ?? 0,
            'currency' => $data['currency'] ?? 'INR',
            'limits' => $this->cleanLimits($data['limits'] ?? []),
            'features' => $this->cleanFeatures($data['features'] ?? []),
            'is_active' => $data['is_active'] ?? $plan->is_active,
            'is_public' => $data['is_public'] ?? $plan->is_public,
            'sort_order' => $data['sort_order'] ?? $plan->sort_order,
        ]);

        return $this->ok(['plan' => $this->planArray($plan->fresh())]);
    }

    public function toggle(Request $request, string $uuid): JsonResponse
    {
        $this->ensureSuperAdmin($request);
        $plan = Plan::where('uuid', $uuid)->firstOrFail();
        $plan->update(['is_active' => ! $plan->is_active]);

        return $this->ok(['plan' => $this->planArray($plan->fresh())]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->ensureSuperAdmin($request);
        $plan = Plan::where('uuid', $uuid)->firstOrFail();

        if ($plan->subscriptions()->exists()) {
            return $this->fail('This plan is assigned to companies and cannot be deleted. Deactivate it instead.', [], 422);
        }
        $plan->delete();

        return $this->ok(['message' => 'Plan deleted.']);
    }

    // ---- helpers ----

    private function validatePlan(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'billing_period' => ['nullable', 'string', 'in:monthly,yearly'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:3'],
            'limits' => ['nullable', 'array'],
            'features' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);
    }

    /** Keep numeric limits; a value < 0 or empty means unlimited (stored as -1). */
    private function cleanLimits(array $limits): array
    {
        $out = [];
        foreach ($limits as $key => $val) {
            if ($val === '' || $val === null) {
                $out[$key] = -1; // unlimited
            } else {
                $out[$key] = (int) $val;
            }
        }
        return $out;
    }

    private function cleanFeatures(array $features): array
    {
        $out = [];
        foreach ($features as $key => $val) {
            $out[$key] = (bool) $val;
        }
        return $out;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'plan';
        $slug = $base;
        $i = 1;
        while (Plan::where('slug', $slug)->exists()) {
            $slug = "{$base}-" . (++$i);
        }
        return $slug;
    }

    private function planArray(Plan $p): array
    {
        return [
            'id' => $p->uuid,
            'name' => $p->name,
            'slug' => $p->slug,
            'description' => $p->description,
            'billing_period' => $p->billing_period,
            'price' => (float) $p->price,
            'currency' => $p->currency,
            'limits' => (object) ($p->limits ?? []),
            'features' => (object) ($p->features ?? []),
            'is_active' => $p->is_active,
            'is_public' => $p->is_public,
            'sort_order' => $p->sort_order,
            'subscribers' => $p->subscriptions()->count(),
        ];
    }

    private function ensureSuperAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_super_admin, 403, 'Super admin only.');
    }
}
