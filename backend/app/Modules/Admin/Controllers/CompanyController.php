<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Auth\Actions\RegisterTenantAction;
use App\Modules\Auth\DTOs\RegisterData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Platform (super-admin) management of companies. A "company" is a tenant plus
 * its owner account. Only super admins may use these endpoints.
 */
class CompanyController extends Controller
{
    public function __construct(private readonly RegisterTenantAction $register) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $tenants = Tenant::query()
            ->withCount(['users'])
            ->orderByDesc('created_at')
            ->get();

        $limits = app(\App\Modules\Billing\Services\PlanLimitService::class);

        return $this->ok([
            'companies' => $tenants->map(function (Tenant $t) use ($limits) {
                $owner = \App\Models\User::where('tenant_id', $t->id)->orderBy('id')->first();
                $plan = $limits->planFor($t->id);
                $agentLimit = $limits->limit($t->id, 'max_agents');
                return [
                    'id' => $t->uuid ?? (string) $t->id,
                    'name' => $t->company_name ?? $t->name,
                    'status' => $t->status,
                    'users_count' => $t->users_count,
                    'owner_name' => $owner?->name,
                    'owner_email' => $owner?->email,
                    'plan_id' => $plan?->uuid,
                    'plan_name' => $plan?->name,
                    'agents_used' => $limits->usage($t->id, 'agents'),
                    'agents_limit' => $agentLimit, // null = unlimited
                    'trial_ends_at' => $t->trial_ends_at?->toIso8601String(),
                    'created_at' => $t->created_at?->toIso8601String(),
                ];
            }),
            'plans' => Plan::where('is_active', true)->orderBy('price')->orderBy('sort_order')
                ->get()->map(fn (Plan $p) => ['id' => $p->uuid, 'name' => $p->name]),
        ]);
    }

    /** Set a tenant's plan by pointing its (latest) subscription at the plan. */
    private function setTenantPlan(int $tenantId, string $planUuid): void
    {
        $plan = Plan::where('uuid', $planUuid)->firstOrFail();
        $sub = Subscription::withoutGlobalScopes()->where('tenant_id', $tenantId)->latest('id')->first();
        $attrs = [
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => $plan->billing_period === 'yearly' ? now()->addYear() : now()->addMonth(),
        ];
        if ($sub) {
            $sub->update($attrs);
        } else {
            Subscription::create(array_merge($attrs, ['tenant_id' => $tenantId]));
        }
    }

    public function assignPlan(Request $request, string $uuid): JsonResponse
    {
        $this->ensureSuperAdmin($request);
        $data = $request->validate(['plan_id' => ['required', 'string', 'exists:plans,uuid']]);
        $tenant = $this->findTenant($uuid);
        $this->setTenantPlan($tenant->id, $data['plan_id']);

        return $this->ok(['message' => 'Plan assigned.']);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:32'],
            'plan_id' => ['nullable', 'string', 'exists:plans,uuid'],
        ]);

        // Preserve the super admin's auth context — the action logs in as the new
        // owner while provisioning tenant records.
        $superAdmin = $request->user();

        $owner = $this->register->execute(new RegisterData(
            companyName: $data['company_name'],
            name: $data['owner_name'],
            email: $data['owner_email'],
            password: $data['password'],
            phone: $data['phone'] ?? null,
        ));

        auth('api')->setUser($superAdmin);

        $tenant = $owner->tenant;

        if (! empty($data['plan_id'])) {
            $this->setTenantPlan($tenant->id, $data['plan_id']);
        }

        return $this->ok([
            'company' => [
                'id' => $tenant->uuid ?? (string) $tenant->id,
                'name' => $tenant->company_name ?? $tenant->name,
                'status' => $tenant->status,
                'owner_name' => $owner->name,
                'owner_email' => $owner->email,
                'users_count' => 1,
                'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
                'created_at' => $tenant->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Super admin updates a company and its owner (admin) account:
     * company name, owner name/email/mobile and — optionally — a new password.
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $this->ensureSuperAdmin($request);
        $tenant = $this->findTenant($uuid);
        $owner = User::where('tenant_id', $tenant->id)->orderBy('id')->first();

        $data = $request->validate([
            'company_name' => ['sometimes', 'required', 'string', 'max:255'],
            'owner_name' => ['sometimes', 'required', 'string', 'max:255'],
            'owner_email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($owner?->id)],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        if (array_key_exists('company_name', $data)) {
            $tenant->update(['company_name' => $data['company_name'], 'name' => $data['company_name']]);
        }

        if ($owner) {
            $u = [];
            if (array_key_exists('owner_name', $data)) $u['name'] = $data['owner_name'];
            if (array_key_exists('owner_email', $data)) $u['email'] = $data['owner_email'];
            if (array_key_exists('phone', $data)) $u['phone'] = $data['phone'];
            if (! empty($data['password'])) $u['password'] = Hash::make($data['password']);
            if ($u) $owner->update($u);
        }

        return $this->ok([
            'message' => 'Company updated successfully.',
            'company' => [
                'id' => $tenant->uuid ?? (string) $tenant->id,
                'name' => $tenant->company_name ?? $tenant->name,
                'owner_name' => $owner?->name,
                'owner_email' => $owner?->email,
                'status' => $tenant->status,
            ],
        ]);
    }

    /**
     * Platform-wide payment history: every plan invoice across all companies
     * (online Razorpay payments and free activations), newest first.
     */
    public function payments(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $invoices = \App\Models\Invoice::withoutGlobalScopes()
            ->orderByDesc('issued_at')->orderByDesc('id')
            ->limit(500)->get();

        $names = Tenant::query()->pluck('company_name', 'id');
        $altNames = Tenant::query()->pluck('name', 'id');

        $paid = $invoices->where('status', 'paid');

        return $this->ok([
            'payments' => $invoices->map(fn ($inv) => [
                'id' => $inv->uuid,
                'number' => $inv->number,
                'company' => $names[$inv->tenant_id] ?? $altNames[$inv->tenant_id] ?? ('Company #' . $inv->tenant_id),
                'customer' => data_get($inv->meta, 'customer.name'),
                'plan' => data_get($inv->meta, 'plan_name'),
                'status' => $inv->status,
                'gateway' => $inv->gateway,
                'payment_id' => $inv->gateway_invoice_id,
                'total' => $this->money($inv->total_minor, $inv->currency),
                'issued_at' => $inv->issued_at?->toIso8601String(),
            ])->values(),
            'stats' => [
                'count' => $invoices->count(),
                'paid' => $paid->count(),
                'revenue' => $this->money((int) $paid->sum('total_minor')),
            ],
        ]);
    }

    /** Format minor units (paise) into a currency string like "₹1,250.00". */
    private function money(int $minor, string $currency = 'INR'): string
    {
        $symbol = $currency === 'INR' ? '₹' : ($currency === 'USD' ? '$' : $currency . ' ');
        return $symbol . number_format($minor / 100, 2);
    }

    /** All users (owner + agents) of a company — for super-admin management. */
    public function users(Request $request, string $uuid): JsonResponse
    {
        $this->ensureSuperAdmin($request);
        $tenant = $this->findTenant($uuid);

        $users = User::where('tenant_id', $tenant->id)->orderBy('id')->get();

        return $this->ok([
            'users' => $users->map(fn (User $u) => [
                'id' => $u->uuid,
                'name' => $u->name,
                'email' => $u->email,
                'status' => $u->status,
                'role' => $u->hasRole('tenant-owner') ? 'Admin' : 'Agent',
            ]),
        ]);
    }

    /** Super admin resets any company user's (Admin or Agent) password. */
    public function resetUserPassword(Request $request, string $uuid, string $userUuid): JsonResponse
    {
        $this->ensureSuperAdmin($request);
        $tenant = $this->findTenant($uuid);

        $data = $request->validate(['password' => ['required', 'string', 'min:8']]);

        $user = User::where('uuid', $userUuid)->where('tenant_id', $tenant->id)->firstOrFail();
        $user->update(['password' => \Illuminate\Support\Facades\Hash::make($data['password'])]);

        return $this->ok(['message' => "Password updated for {$user->name}."]);
    }

    public function toggle(Request $request, string $uuid): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $tenant = $this->findTenant($uuid);
        $suspend = $tenant->status !== 'suspended';

        $tenant->update([
            'status' => $suspend ? 'suspended' : 'active',
            'suspended_at' => $suspend ? now() : null,
        ]);

        return $this->ok(['message' => $suspend ? 'Company suspended.' : 'Company reactivated.', 'status' => $tenant->status]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $tenant = $this->findTenant($uuid);
        $tenant->delete();

        return $this->ok(['message' => 'Company deleted.']);
    }

    /** Super-admin: add AI credits to a company's wallet. */
    public function addCredits(Request $request, string $uuid, \App\Modules\Billing\Services\CreditService $credits): JsonResponse
    {
        $this->ensureSuperAdmin($request);
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1', 'max:1000000']]);
        $tenant = $this->findTenant($uuid);

        $wallet = $credits->add($tenant->id, (int) $data['amount'], 'admin_topup', 'Admin top-up', $request->user()->id);

        return $this->ok(['message' => "Added {$data['amount']} credits.", 'balance' => $wallet->balance]);
    }

    private function findTenant(string $uuid): Tenant
    {
        return Tenant::where('uuid', $uuid)->firstOrFail();
    }

    private function ensureSuperAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_super_admin, 403, 'Super admin only.');
    }
}
