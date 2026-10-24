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
 * The Business Information editor: view and save the Google Business Profile
 * fields (About / Contact / Location / Hours / More). Edits persist in Eydia;
 * the live Google push is best-effort and only runs when connected.
 */
class GbpProfileTest extends TestCase
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
            'tenant_id' => $owner->tenant_id,
            'plan_id' => Plan::where('slug', 'enterprise')->value('id'),
            'status' => 'active', 'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        ]);

        return $owner;
    }

    public function test_profile_returns_defaults_with_seven_days_of_hours(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')
            ->getJson('/api/v1/gbp/profile')
            ->assertOk()
            ->assertJsonPath('data.connected', false)
            ->assertJsonCount(7, 'data.profile.hours')
            ->assertJsonCount(8, 'data.attribute_groups');
    }

    public function test_saving_creates_the_location_and_persists_edits(): void
    {
        $owner = $this->owner();

        $payload = [
            'name' => 'VezetoLMS',
            'category' => 'Software company',
            'description' => 'We build learning platforms.',
            'phone' => '099997 34593',
            'chat_url' => 'https://wa.me/919999734593',
            'website' => 'https://www.heltog.com/',
            'address' => 'A778, Naveen Nagar, Sector 19, Noida',
            'service_area' => ['Noida', 'Delhi'],
            'hours' => [
                ['day' => 'MONDAY', 'open' => '10:00', 'close' => '18:00', 'closed' => false],
                ['day' => 'SUNDAY', 'open' => '10:00', 'close' => '18:00', 'closed' => true],
            ],
            'attributes' => ['Amenities' => ['Free Wi-Fi'], 'Accessibility' => ['Wheelchair accessible']],
        ];

        $res = $this->actingAs($owner, 'api')->putJson('/api/v1/gbp/profile', $payload)
            ->assertOk()
            ->assertJsonPath('data.synced_to_google', false)
            ->assertJsonPath('data.profile.name', 'VezetoLMS')
            ->assertJsonPath('data.profile.phone', '099997 34593')
            ->assertJsonPath('data.profile.service_area.0', 'Noida')
            ->assertJsonPath('data.profile.attributes.Amenities.0', 'Free Wi-Fi');

        // Hours normalize back to the full Mon–Sun week.
        $this->assertCount(7, $res->json('data.profile.hours'));

        $loc = GbpLocation::withoutGlobalScopes()->where('tenant_id', $owner->tenant_id)->first();
        $this->assertNotNull($loc);
        $this->assertSame('VezetoLMS', $loc->name);
        $this->assertSame('We build learning platforms.', $loc->meta['description']);
        $this->assertTrue(collect($loc->meta['hours'])->firstWhere('day', 'SUNDAY')['closed']);

        // A second save updates the same row (no duplicate location).
        $this->actingAs($owner, 'api')->putJson('/api/v1/gbp/profile', ['website' => 'https://eydia.in'])
            ->assertOk()->assertJsonPath('data.profile.website', 'https://eydia.in');
        $this->assertSame(1, GbpLocation::withoutGlobalScopes()->where('tenant_id', $owner->tenant_id)->count());
    }

    public function test_review_share_links_build_from_a_review_url(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')->putJson('/api/v1/gbp/profile', [
            'name' => 'VezetoLMS',
            'review_url' => 'https://g.page/r/CTWFgTQrZoJaEBM/review',
        ])->assertOk()
            ->assertJsonPath('data.profile.review_url', 'https://g.page/r/CTWFgTQrZoJaEBM/review')
            ->assertJsonPath('data.profile.share.whatsapp', fn ($v) => str_contains((string) $v, 'wa.me/?text='))
            ->assertJsonPath('data.profile.share.facebook', fn ($v) => str_contains((string) $v, 'facebook.com/sharer'));
    }
}
