<?php

namespace Tests\Feature;

use App\Models\GbpLocation;
use App\Models\Plan;
use App\Models\PlanLocationSlot;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan-based GBP location slots: the connect limit, the permanent (no-swap) slot
 * rule and reserve-on-disconnect, all enforced server-side.
 */
class GbpLocationSlotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function owner(int $locationLimit): User
    {
        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Owner', 'email' => 'owner@acme.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertCreated();
        $owner = User::withoutGlobalScopes()->where('email', 'owner@acme.test')->first();

        $plan = Plan::create([
            'name' => 'Slotted', 'slug' => 'slotted', 'billing_period' => 'monthly',
            'price' => 100, 'currency' => 'INR',
            'limits' => ['location_limit' => $locationLimit],
            'features' => ['dash_gbp' => true],
            'is_active' => true, 'is_public' => true,
        ]);
        Subscription::create([
            'tenant_id' => $owner->tenant_id, 'plan_id' => $plan->id,
            'status' => 'active', 'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        ]);

        return $owner;
    }

    /** @return GbpLocation[] */
    private function makeLocations(User $owner, int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = GbpLocation::create([
                'tenant_id' => $owner->tenant_id,
                'google_location_id' => "locations/{$i}",
                'name' => "Location {$i}",
                'is_hidden' => true,
            ]);
        }

        return $out;
    }

    private function connect(User $o, GbpLocation $l)
    {
        return $this->actingAs($o, 'api')->postJson("/api/v1/gbp/locations/{$l->uuid}/connect");
    }

    private function disconnect(User $o, GbpLocation $l)
    {
        return $this->actingAs($o, 'api')->postJson("/api/v1/gbp/locations/{$l->uuid}/disconnect");
    }

    public function test_connect_is_blocked_once_the_plan_limit_is_reached(): void
    {
        $owner = $this->owner(locationLimit: 2);
        [$l1, $l2, $l3] = $this->makeLocations($owner, 3);

        $this->connect($owner, $l1)->assertOk();
        $this->connect($owner, $l2)->assertOk();

        // The 3rd NEW location exceeds the limit of 2.
        $this->connect($owner, $l3)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Location limit reached — upgrade your plan to connect more locations.');

        $this->assertSame(2, PlanLocationSlot::withoutGlobalScopes()->where('tenant_id', $owner->tenant_id)->count());
        // Connected locations become visible; the blocked one stays hidden.
        $this->assertFalse((bool) $l1->fresh()->is_hidden);
        $this->assertTrue((bool) $l3->fresh()->is_hidden);
    }

    public function test_disconnect_reserves_the_slot_and_blocks_a_different_location(): void
    {
        $owner = $this->owner(locationLimit: 2);
        [$l1, $l2, $l3] = $this->makeLocations($owner, 3);

        $this->connect($owner, $l1)->assertOk();
        $this->connect($owner, $l2)->assertOk();

        // Disconnect L1 — its slot must stay reserved (still counts against limit).
        $this->disconnect($owner, $l1)->assertOk();
        $this->assertTrue((bool) $l1->fresh()->is_hidden);
        $slot = PlanLocationSlot::withoutGlobalScopes()->where('location_key', 'locations/1')->first();
        $this->assertSame('disconnected', $slot->status);
        $this->assertSame(2, PlanLocationSlot::withoutGlobalScopes()->where('tenant_id', $owner->tenant_id)->count());

        // A DIFFERENT new location cannot take the freed slot (no swapping).
        $this->connect($owner, $l3)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Location limit reached — upgrade your plan to connect more locations.');

        // The SAME location may reconnect to its reserved slot.
        $this->connect($owner, $l1)->assertOk();
        $this->assertSame('connected', $slot->fresh()->status);
        $this->assertFalse((bool) $l1->fresh()->is_hidden);
        // Still exactly 2 slots, same slot numbers — nothing new was created.
        $this->assertSame(2, PlanLocationSlot::withoutGlobalScopes()->where('tenant_id', $owner->tenant_id)->count());
    }

    public function test_overview_exposes_limit_and_connection_state(): void
    {
        $owner = $this->owner(locationLimit: 2);
        [$l1] = $this->makeLocations($owner, 2);
        $this->connect($owner, $l1)->assertOk();

        $res = $this->actingAs($owner, 'api')->getJson('/api/v1/gbp/overview')->assertOk();
        $this->assertSame(2, $res->json('data.location_limit'));
        $this->assertSame(1, $res->json('data.slots_connected'));
        // The connected location reports connected=true with a slot number.
        $connected = collect($res->json('data.locations'))->firstWhere('id', $l1->uuid);
        $this->assertTrue($connected['connected']);
        $this->assertSame(1, $connected['slot_no']);
    }
}
