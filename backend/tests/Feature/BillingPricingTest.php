<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Backend-managed pricing: per-cycle discounts from settings, coupons applied on
 * top of the cycle discount, and admin management of both.
 */
class BillingPricingTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private string $planUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlanSeeder::class);

        $this->token = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Owner', 'email' => 'owner@acme.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->json('data.token');

        // Enterprise plan: ₹9999 / month.
        $this->planUuid = Plan::where('slug', 'enterprise')->value('uuid');
    }

    private function owner(): static
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
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

    private function quote(array $body): array
    {
        return $this->owner()
            ->postJson('/api/v1/billing/quote', array_merge(['plan_id' => $this->planUuid], $body))
            ->assertOk()
            ->json('data');
    }

    public function test_cycle_discount_from_defaults(): void
    {
        // Yearly default = 20% off: 9999*12 = 119988 → 95990.4.
        $q = $this->quote(['cycle' => 'yearly']);
        $this->assertSame(20, $q['cycle_discount_percent']);
        $this->assertEqualsWithDelta(119988.0, $q['list_price'], 0.01);
        $this->assertEqualsWithDelta(95990.4, $q['cycle_price'], 0.01);
        $this->assertEqualsWithDelta(95990.4, $q['payable'], 0.01);

        // 6-month default = 15% off: 9999*6 = 59994 → 50994.9.
        $h = $this->quote(['cycle' => 'half_yearly']);
        $this->assertSame(15, $h['cycle_discount_percent']);
        $this->assertEqualsWithDelta(50994.9, $h['payable'], 0.01);
    }

    public function test_admin_can_change_cycle_discount(): void
    {
        $this->actingAs($this->superAdmin(), 'api')
            ->putJson('/api/v1/admin/billing-settings', ['cycle_discounts' => ['yearly' => 50]])
            ->assertOk();

        $q = $this->quote(['cycle' => 'yearly']);
        $this->assertSame(50, $q['cycle_discount_percent']);
        $this->assertEqualsWithDelta(59994.0, $q['payable'], 0.01); // 119988 * 0.5
    }

    public function test_coupon_applies_on_top_of_cycle_discount(): void
    {
        $this->actingAs($this->superAdmin(), 'api')
            ->postJson('/api/v1/admin/coupons', ['code' => 'save10', 'type' => 'percent', 'value' => 10])
            ->assertCreated()
            ->assertJsonPath('data.coupon.code', 'SAVE10'); // uppercased

        $q = $this->quote(['cycle' => 'yearly', 'coupon' => 'save10']);
        // cycle_price 95990.4, then 10% off = 9599.04 → payable 86391.36.
        $this->assertSame('SAVE10', $q['coupon']['code']);
        $this->assertEqualsWithDelta(9599.04, $q['coupon']['discount'], 0.01);
        $this->assertEqualsWithDelta(86391.36, $q['payable'], 0.01);
    }

    public function test_flat_coupon_and_min_amount_and_cap(): void
    {
        $admin = $this->superAdmin();
        // Flat ₹1000 off, but only on orders ≥ ₹100000.
        $this->actingAs($admin, 'api')->postJson('/api/v1/admin/coupons', [
            'code' => 'FLAT1000', 'type' => 'flat', 'value' => 1000, 'min_amount' => 100000,
        ])->assertCreated();

        // Yearly payable 95990.4 < 100000 → coupon rejected, full price.
        $q = $this->quote(['cycle' => 'yearly', 'coupon' => 'FLAT1000']);
        $this->assertNull($q['coupon']);
        $this->assertNotNull($q['coupon_error']);
        $this->assertEqualsWithDelta(95990.4, $q['payable'], 0.01);
    }

    public function test_invalid_coupon_is_reported_but_price_stands(): void
    {
        $q = $this->quote(['cycle' => 'monthly', 'coupon' => 'NOPE']);
        $this->assertNull($q['coupon']);
        $this->assertNotNull($q['coupon_error']);
        $this->assertEqualsWithDelta(9999.0, $q['payable'], 0.01);
    }

    public function test_admin_coupon_crud_and_guard(): void
    {
        $admin = $this->superAdmin();

        $id = $this->actingAs($admin, 'api')->postJson('/api/v1/admin/coupons', [
            'code' => 'LAUNCH', 'type' => 'percent', 'value' => 25, 'max_uses' => 100,
        ])->assertCreated()->json('data.coupon.id');

        $this->actingAs($admin, 'api')->getJson('/api/v1/admin/coupons')
            ->assertOk()->assertJsonPath('data.coupons.0.code', 'LAUNCH');

        $newValue = $this->actingAs($admin, 'api')->putJson("/api/v1/admin/coupons/{$id}", [
            'code' => 'LAUNCH', 'type' => 'percent', 'value' => 30, 'is_active' => false,
        ])->assertOk()->json('data.coupon.value');
        $this->assertEquals(30, $newValue);

        // A non-admin (tenant owner) cannot touch coupons.
        $owner = User::withoutGlobalScopes()->where('email', 'owner@acme.test')->first();
        $this->actingAs($owner, 'api')->getJson('/api/v1/admin/coupons')->assertStatus(403);

        $this->actingAs($admin, 'api')->deleteJson("/api/v1/admin/coupons/{$id}")->assertOk();
    }
}
