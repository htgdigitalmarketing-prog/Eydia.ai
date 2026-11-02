<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The on-demand "Run now" automation endpoint and AI profile auto-setup guard
 * their preconditions cleanly (no automations on / no business / no AI provider)
 * instead of silently doing nothing.
 */
class GbpAutomationRunTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlanSeeder::class);

        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Owner', 'email' => 'owner@acme.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertCreated();
        $this->owner = User::withoutGlobalScopes()->where('email', 'owner@acme.test')->first();
        Subscription::create([
            'tenant_id' => $this->owner->tenant_id,
            'plan_id' => Plan::where('slug', 'enterprise')->value('id'),
            'status' => 'active', 'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        ]);
    }

    public function test_run_now_rejects_when_no_automation_enabled(): void
    {
        // Fresh tenant: all automation switches default off.
        $this->actingAs($this->owner, 'api')
            ->postJson('/api/v1/gbp/automation/run')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Turn on at least one automation first.');
    }

    public function test_auto_setup_profile_requires_a_business(): void
    {
        $this->actingAs($this->owner, 'api')
            ->postJson('/api/v1/gbp/profile/auto-setup')
            ->assertStatus(422);
    }
}
