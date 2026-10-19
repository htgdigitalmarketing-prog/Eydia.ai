<?php

namespace App\Modules\Auth\Resources;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public-safe user shape. Exposes the uuid (never the internal id) and never
 * password / token / 2FA columns.
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'avatar_url' => $this->avatar_url,
            'is_super_admin' => (bool) $this->is_super_admin,
            'status' => $this->status,
            'email_verified' => $this->email_verified_at !== null,
            'roles' => $this->getRoleNames(),
            'permissions' => $this->getAllPermissions()->pluck('name'),
            // Enabled plan feature keys (social/chatbot/automations/reports/...).
            // null = no plan / super admin → no feature gating (all available).
            'plan_features' => (! $this->is_super_admin && $this->tenant_id)
                ? app(\App\Modules\Billing\Services\PlanLimitService::class)->planFeatures($this->tenant_id)
                : null,
            // Numeric plan limits (key => int, or null = unlimited). 0 = blocked.
            // null map for super admin / no tenant → no limit gating.
            'plan_limits' => (! $this->is_super_admin && $this->tenant_id)
                ? app(\App\Modules\Billing\Services\PlanLimitService::class)->limitsMap($this->tenant_id)
                : null,
            'subscription' => $this->subscriptionSummary(),
            'tenant' => new TenantResource($this->whenLoaded('tenant')),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }

    private function subscriptionSummary(): ?array
    {
        if ($this->is_super_admin || ! $this->tenant_id) {
            return null;
        }

        $sub = Subscription::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant_id)
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

        return [
            'status' => $sub->status,
            'plan_name' => $sub->plan?->name,
            'current_period_start' => $sub->current_period_start?->toIso8601String(),
            'current_period_end' => $sub->current_period_end?->toIso8601String(),
        ];
    }
}
