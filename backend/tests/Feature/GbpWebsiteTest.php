<?php

namespace Tests\Feature;

use App\Models\BusinessSite;
use App\Models\GbpLocation;
use App\Models\GbpReview;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The instant GBP business website + report generator (public microsite).
 */
class GbpWebsiteTest extends TestCase
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
            'tenant_id' => $owner->tenant_id, 'plan_id' => Plan::where('slug', 'enterprise')->value('id'),
            'status' => 'active', 'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        ]);

        return $owner;
    }

    public function test_generate_then_serve_public_site(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'api');

        $loc = GbpLocation::create([
            'tenant_id' => $owner->tenant_id, 'name' => 'Bright Dental Care',
            'address' => '5 Park Street, Pune', 'phone' => '+912012345678',
            'category' => 'Dental Clinic', 'rating' => 4.7, 'review_count' => 12, 'is_verified' => true,
        ]);
        GbpReview::create([
            'tenant_id' => $owner->tenant_id, 'gbp_location_id' => $loc->id,
            'author_name' => 'Asha', 'rating' => 5, 'comment' => 'Painless and professional!', 'reviewed_at' => now()->subDay(),
        ]);

        $res = $this->postJson('/api/v1/gbp/site/generate')
            ->assertOk()
            ->assertJsonPath('data.site.status', 'published')
            ->assertJsonPath('data.site.slug', 'bright-dental-care');
        $url = $res->json('data.site.url');
        $this->assertStringContainsString('/site/bright-dental-care', $url);

        // Re-generating is idempotent (same slug, still one row).
        $this->postJson('/api/v1/gbp/site/generate')->assertOk()->assertJsonPath('data.site.slug', 'bright-dental-care');
        $this->assertSame(1, BusinessSite::withoutGlobalScopes()->where('tenant_id', $owner->tenant_id)->count());

        // The public page renders the business + its review, no auth.
        $this->get('/site/bright-dental-care')
            ->assertOk()
            ->assertSee('Bright Dental Care')
            ->assertSee('Your Business Health Report')
            ->assertSee('Painless and professional!');

        $this->get('/site/nope-not-real')->assertNotFound();
    }

    public function test_generate_requires_a_location(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'api')
            ->postJson('/api/v1/gbp/site/generate')
            ->assertStatus(422);
    }
}
