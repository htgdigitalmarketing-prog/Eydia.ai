<?php

namespace Tests\Feature;

use App\Models\CreditWallet;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\Billing\Services\CreditService;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Credits are granted once from the plan and never reset monthly — they persist
 * as they are spent, and running out means buying a top-up pack.
 */
class CreditNoResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlanSeeder::class);
    }

    private function owner(): User
    {
        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Owner', 'email' => 'owner@acme.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertCreated();
        $owner = User::withoutGlobalScopes()->where('email', 'owner@acme.test')->first();
        Subscription::create([
            'tenant_id' => $owner->tenant_id, 'plan_id' => Plan::where('slug', 'growth')->value('id'),
            'status' => 'active', 'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        ]);

        return $owner;
    }

    public function test_balance_does_not_reset_after_the_period(): void
    {
        $owner = $this->owner();
        $credits = app(CreditService::class);

        $this->assertSame(1000, $credits->balance($owner->tenant_id)); // Growth = 1000 once

        $credits->deduct($owner->tenant_id, $owner->id, 'ai_media_generate', 'test');
        $this->assertSame(995, $credits->balance($owner->tenant_id));

        // Simulate a month passing: the balance must stay as spent (no refill).
        CreditWallet::withoutGlobalScopes()->where('tenant_id', $owner->tenant_id)
            ->update(['reset_at' => now()->subMonth()]);

        $this->assertSame(995, $credits->balance($owner->tenant_id));
    }
}
