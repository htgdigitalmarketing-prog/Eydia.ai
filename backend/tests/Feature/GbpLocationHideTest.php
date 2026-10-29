<?php

namespace Tests\Feature;

use App\Models\GbpLocation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hiding synced locations: a connected Google account can manage many locations,
 * so the tenant can hide the ones they don't want driving the dashboard.
 */
class GbpLocationHideTest extends TestCase
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

    private function makeLocation(string $name): GbpLocation
    {
        return GbpLocation::withoutGlobalScopes()->create([
            'tenant_id' => $this->owner->tenant_id, 'name' => $name,
            'google_location_id' => 'loc-' . strtolower($name), 'review_count' => 5,
        ]);
    }

    public function test_hiding_removes_a_location_from_the_dashboard(): void
    {
        $mine = $this->makeLocation('MyBiz');
        $other = $this->makeLocation('SomeoneElse');

        $this->actingAs($this->owner, 'api')->getJson('/api/v1/gbp/overview')
            ->assertOk()
            ->assertJsonCount(2, 'data.locations')
            ->assertJsonCount(0, 'data.hidden_locations');

        // Hide the other business.
        $this->actingAs($this->owner, 'api')
            ->postJson("/api/v1/gbp/locations/{$other->uuid}/hidden", ['hidden' => true])
            ->assertOk();

        $this->actingAs($this->owner, 'api')->getJson('/api/v1/gbp/overview')
            ->assertOk()
            ->assertJsonCount(1, 'data.locations')
            ->assertJsonPath('data.locations.0.name', 'MyBiz')
            ->assertJsonCount(1, 'data.hidden_locations')
            ->assertJsonPath('data.hidden_locations.0.name', 'SomeoneElse');

        // Un-hide restores it.
        $this->actingAs($this->owner, 'api')
            ->postJson("/api/v1/gbp/locations/{$other->uuid}/hidden", ['hidden' => false])
            ->assertOk();

        $this->actingAs($this->owner, 'api')->getJson('/api/v1/gbp/overview')
            ->assertOk()->assertJsonCount(2, 'data.locations');
    }
}
