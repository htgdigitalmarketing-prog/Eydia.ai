<?php

namespace App\Modules\Billing\Services;

use App\Models\Campaign;
use App\Models\Chatbot;
use App\Models\Contact;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Template;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Central plan-limit + feature engine. Limits and feature flags live in the
 * plan's `limits` / `features` JSON (no hard-coded numbers), so new limits and
 * features can be added by editing a plan — never by changing code.
 *
 * A limit that is absent, null, or < 0 means UNLIMITED.
 */
class PlanLimitService
{
    /** The tenant's current plan (via its latest subscription), or null if expired/absent. */
    public function planFor(int $tenantId): ?Plan
    {
        $sub = Subscription::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->with('plan')
            ->first();

        if (! $sub) {
            return null;
        }

        if (
            in_array($sub->status, ['active', 'trialing'], true)
            && $sub->current_period_end
            && $sub->current_period_end->isPast()
        ) {
            $sub->update(['status' => 'expired']);
        }

        if (! in_array($sub->status, ['active', 'trialing'], true)) {
            return null;
        }

        return $sub->plan;
    }

    /** Numeric limit for a key. null return = unlimited. */
    public function limit(int $tenantId, string $key): ?int
    {
        $plan = $this->planFor($tenantId);
        if (! $plan) {
            return null; // no plan → treat as unlimited so nothing breaks
        }
        $v = $plan->limit($key);
        if ($v === null || $v === '' || (int) $v < 0) {
            return null; // unlimited
        }
        return (int) $v;
    }

    /** Whether a plan feature flag is enabled. No plan → allowed by default. */
    public function feature(int $tenantId, string $key): bool
    {
        $plan = $this->planFor($tenantId);
        if (! $plan) {
            return true;
        }
        return (bool) data_get($plan->features, $key, false);
    }

    /** Current usage for a metric key. */
    public function usage(int $tenantId, string $key): int
    {
        return match ($key) {
            'agents' => User::where('tenant_id', $tenantId)
                ->where('is_super_admin', false)
                ->whereHas('roles', fn ($q) => $q->where('name', 'agent'))
                ->count(),
            'contacts' => Contact::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
            'campaigns' => Campaign::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
            'chatbots' => Chatbot::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
            'templates' => Template::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
            default => 0,
        };
    }

    /** The standard limit keys as a map (value: int limit, or null = unlimited). */
    public function limitsMap(int $tenantId): array
    {
        $keys = ['max_agents', 'max_contacts', 'max_campaigns', 'max_chatbots', 'max_templates'];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->limit($tenantId, $k); // null = unlimited, else int (0 = blocked)
        }
        return $out;
    }

    /**
     * Throw a friendly error when the tenant can't add/use a metered feature:
     * limit 0 = plan doesn't include it at all; used >= limit = quota reached.
     */
    public function assertWithinLimit(int $tenantId, string $limitKey, string $usageKey, string $noun): void
    {
        $limit = $this->limit($tenantId, $limitKey);
        if ($limit === null) {
            return; // unlimited
        }
        if ($limit === 0) {
            throw ValidationException::withMessages([
                'plan' => ["Your current plan does not include {$noun}. Please upgrade your plan to use this feature."],
            ]);
        }
        if ($this->usage($tenantId, $usageKey) >= $limit) {
            throw ValidationException::withMessages([
                'plan' => ["Your current plan allows a maximum of {$limit} {$noun}. Please upgrade your plan to add more."],
            ]);
        }
    }

    /**
     * Returns [allowed, limit, used, remaining]. limit === null means unlimited.
     */
    public function status(int $tenantId, string $limitKey, string $usageKey): array
    {
        $limit = $this->limit($tenantId, $limitKey);
        $used = $this->usage($tenantId, $usageKey);
        return [
            'allowed' => $limit === null || $used < $limit,
            'limit' => $limit,
            'used' => $used,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
        ];
    }

    /** Throw a friendly validation error if the tenant is at its agent limit. */
    public function assertCanAddAgent(int $tenantId): void
    {
        $this->assertWithinLimit($tenantId, 'max_agents', 'agents', 'agents');
    }

    /**
     * Feature keys the plan makes available — used to gate which permissions an
     * admin may grant to an agent (an agent can never exceed the plan).
     * Returns null when the plan has no features map (→ allow all).
     */
    public function planFeatures(int $tenantId): ?array
    {
        $plan = $this->planFor($tenantId);
        if (! $plan || empty($plan->features)) {
            return null;
        }
        return collect($plan->features)
            ->filter(fn ($enabled) => (bool) $enabled)
            ->keys()
            ->all();
    }
}
