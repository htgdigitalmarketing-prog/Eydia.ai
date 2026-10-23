<?php

namespace Tests\Feature;

use App\Models\CreditPack;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Super-admin credit-pack management and the client-facing pack list, with
 * GST-inclusive pricing (price already includes tax).
 */
class CreditPackTest extends TestCase
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

    private function tenantOwner(): User
    {
        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Owner', 'email' => 'owner@acme.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertCreated();
        $owner = User::withoutGlobalScopes()->where('email', 'owner@acme.test')->first();
        Subscription::create([
            'tenant_id' => $owner->tenant_id, 'plan_id' => Plan::where('slug', 'enterprise')->value('id'),
            'status' => 'active', 'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        ]);

        return $owner;
    }

    public function test_super_admin_creates_pack_with_gst_inclusive_split(): void
    {
        $this->actingAs($this->superAdmin(), 'api')
            ->postJson('/api/v1/admin/credit-packs', [
                'name' => 'Starter Pack', 'credits' => 250, 'price' => 499, 'gst_rate' => 18, 'sort_order' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('data.pack.credits', 250)
            ->assertJsonPath('data.pack.price', 499)
            ->assertJsonPath('data.pack.base', 422.88)
            ->assertJsonPath('data.pack.gst', 76.12);

        $this->assertDatabaseHas('credit_packs', ['name' => 'Starter Pack', 'credits' => 250]);
    }

    public function test_non_super_admin_cannot_manage_packs(): void
    {
        $this->actingAs($this->tenantOwner(), 'api')
            ->getJson('/api/v1/admin/credit-packs')
            ->assertStatus(403);
    }

    public function test_client_sees_only_active_packs(): void
    {
        CreditPack::create(['name' => 'Live Pack', 'credits' => 500, 'price' => 999, 'gst_rate' => 18, 'is_active' => true]);
        CreditPack::create(['name' => 'Hidden Pack', 'credits' => 100, 'price' => 199, 'gst_rate' => 18, 'is_active' => false]);

        $this->actingAs($this->tenantOwner(), 'api')
            ->getJson('/api/v1/credits/packs')
            ->assertOk()
            ->assertJsonCount(1, 'data.packs')
            ->assertJsonPath('data.packs.0.name', 'Live Pack');
    }
}
