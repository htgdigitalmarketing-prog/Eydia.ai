<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StalePermissionCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_keeps_access_when_the_permission_cache_is_stale(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlanSeeder::class);

        $token = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Owner', 'email' => 'owner@acme.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->json('data.token');

        Subscription::create([
            'tenant_id' => User::withoutGlobalScopes()->where('email', 'owner@acme.test')->value('tenant_id'),
            'plan_id' => Plan::where('slug', 'enterprise')->value('id'),
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/contacts')->assertOk();

        // Change roles with raw SQL so Spatie's cached copy is NOT flushed — the
        // same state as cache files that outlived a change to the role tables.
        $oldId = DB::table('roles')->where('name', 'tenant-owner')->value('id');
        $newId = DB::table('roles')->insertGetId([
            'name' => 'tenant-owner-v2', 'guard_name' => 'api', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('role_has_permissions')->insertUsing(
            ['permission_id', 'role_id'],
            DB::table('role_has_permissions')->where('role_id', $oldId)->selectRaw('permission_id, ?', [$newId]),
        );
        DB::table('model_has_roles')->where('role_id', $oldId)->update(['role_id' => $newId]);
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/contacts')->assertOk();
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/billing/plans')->assertOk();
    }

    public function test_stale_cache_fallback_never_grants_a_permission_the_user_lacks(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlanSeeder::class);

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

        $agent = User::factory()->forTenant($owner->tenant)->create();
        $agent->assignRole('agent');
        $agentToken = auth('api')->login($agent);

        $this->withHeader('Authorization', "Bearer {$agentToken}")
            ->getJson('/api/v1/billing/plans')
            ->assertStatus(403);
    }
}
