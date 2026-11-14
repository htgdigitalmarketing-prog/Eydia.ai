<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Billing\Services\PlanLimitService;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Super-admin company plan management. Covers the "— No plan —" path (removing a
 * plan), which previously did nothing because an empty plan_id failed validation.
 */
class CompanyPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlanSeeder::class);
    }

    private function superAdmin(): User
    {
        $u = new User(['tenant_id' => null, 'name' => 'Root', 'email' => 'root@eydia.test', 'status' => 'active']);
        $u->password = 'secret123';
        $u->is_super_admin = true;
        $u->email_verified_at = now();
        $u->save();

        return $u;
    }

    private function tenantWithPlan(): Tenant
    {
        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Owner', 'email' => 'owner@acme.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertCreated();
        $owner = User::withoutGlobalScopes()->where('email', 'owner@acme.test')->first();
        Subscription::create([
            'tenant_id' => $owner->tenant_id,
            'plan_id' => Plan::where('slug', 'enterprise')->value('id'),
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        return Tenant::withoutGlobalScopes()->find($owner->tenant_id);
    }

    public function test_super_admin_can_remove_a_plan_back_to_no_plan(): void
    {
        $tenant = $this->tenantWithPlan();
        $plans = app(PlanLimitService::class);
        $this->assertNotNull($plans->planFor($tenant->id)); // starts WITH a plan

        // Selecting "— No plan —" sends an empty plan_id.
        $this->actingAs($this->superAdmin(), 'api')
            ->postJson("/api/v1/admin/companies/{$tenant->uuid}/plan", ['plan_id' => ''])
            ->assertOk()
            ->assertJsonPath('data.message', 'Plan removed.');

        $this->assertNull($plans->planFor($tenant->id)); // dropped to "No plan"
    }

    public function test_super_admin_can_assign_a_plan(): void
    {
        $tenant = $this->tenantWithPlan();
        $plans = app(PlanLimitService::class);
        $growth = Plan::where('slug', 'growth')->first();

        $this->actingAs($this->superAdmin(), 'api')
            ->postJson("/api/v1/admin/companies/{$tenant->uuid}/plan", ['plan_id' => $growth->uuid])
            ->assertOk()
            ->assertJsonPath('data.message', 'Plan assigned.');

        $this->assertSame($growth->id, $plans->planFor($tenant->id)?->id);
    }

    public function test_assigning_an_unknown_plan_is_rejected(): void
    {
        $tenant = $this->tenantWithPlan();

        $this->actingAs($this->superAdmin(), 'api')
            ->postJson("/api/v1/admin/companies/{$tenant->uuid}/plan", ['plan_id' => 'not-a-real-uuid'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan_id');
    }

    public function test_super_admin_can_impersonate_a_company_owner(): void
    {
        $tenant = $this->tenantWithPlan();
        $owner = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('id')->first();

        $res = $this->actingAs($this->superAdmin(), 'api')
            ->postJson("/api/v1/admin/companies/{$tenant->uuid}/impersonate")
            ->assertOk()
            ->assertJsonPath('data.user.email', $owner->email)
            ->assertJsonStructure(['data' => ['token', 'user', 'company']]);

        // The returned token authenticates as that owner.
        $this->withHeader('Authorization', "Bearer {$res->json('data.token')}")
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', $owner->email);
    }

    public function test_non_admin_cannot_impersonate(): void
    {
        $tenant = $this->tenantWithPlan();
        $owner = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('id')->first();

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/admin/companies/{$tenant->uuid}/impersonate")
            ->assertStatus(403);
    }
}
