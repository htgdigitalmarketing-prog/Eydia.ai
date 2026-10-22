<?php

namespace Tests\Feature;

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
 * Covers the AI features added from the ReviewFlow reference: AI review replies +
 * sentiment, GBP post/photo captions, one-click optimization, AI media
 * generation and the marketing chat. Tests run with NO AI provider configured,
 * so every feature must degrade gracefully (source: fallback, no credits spent).
 */
class AiFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlanSeeder::class);
        // Make sure no live AI key leaks in from the environment.
        config(['services.gemini.key' => null, 'services.anthropic.key' => null]);
    }

    private function ownerWithPlan(string $company = 'Acme', string $email = 'owner@acme.test'): User
    {
        $this->postJson('/api/v1/auth/register', [
            'company_name' => $company, 'name' => 'Owner', 'email' => $email,
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertCreated();

        $owner = User::withoutGlobalScopes()->where('email', $email)->first();
        Subscription::create([
            'tenant_id' => $owner->tenant_id,
            'plan_id' => Plan::where('slug', 'enterprise')->value('id'),
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        return $owner;
    }

    public function test_ai_media_generate_falls_back_to_placeholder_without_charging(): void
    {
        $owner = $this->ownerWithPlan();

        $this->actingAs($owner, 'api')
            ->postJson('/api/v1/ai-media', ['prompt' => 'A festive sale banner'])
            ->assertCreated()
            ->assertJsonPath('data.source', 'fallback')
            ->assertJsonPath('data.media.source', 'fallback');

        // Fallback must not spend credits.
        $this->assertDatabaseMissing('credit_ledgers', ['action' => 'ai_media_generate']);
        $this->assertDatabaseHas('ai_media', ['tenant_id' => $owner->tenant_id, 'source' => 'fallback']);
    }

    public function test_ai_media_requires_a_prompt(): void
    {
        $owner = $this->ownerWithPlan();

        $this->actingAs($owner, 'api')
            ->postJson('/api/v1/ai-media', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('prompt');
    }

    public function test_marketing_chat_returns_a_fallback_answer(): void
    {
        $owner = $this->ownerWithPlan();

        $this->actingAs($owner, 'api')
            ->postJson('/api/v1/assistant/chat', ['message' => 'How do I get more reviews?'])
            ->assertOk()
            ->assertJsonPath('data.source', 'fallback')
            ->assertJsonStructure(['data' => ['answer', 'source']]);
    }

    public function test_gbp_optimize_returns_a_prioritized_plan(): void
    {
        $owner = $this->ownerWithPlan();

        $this->actingAs($owner, 'api')
            ->postJson('/api/v1/gbp/optimize')
            ->assertOk()
            ->assertJsonPath('data.source', 'fallback')
            ->assertJsonStructure(['data' => ['summary', 'actions', 'health' => ['score', 'grade']]]);
    }

    public function test_suggest_reply_drafts_a_reply_and_sets_sentiment(): void
    {
        $owner = $this->ownerWithPlan();
        $this->actingAs($owner, 'api');

        $location = GbpLocation::create(['name' => 'Test Store', 'tenant_id' => $owner->tenant_id]);
        $review = GbpReview::create([
            'tenant_id' => $owner->tenant_id,
            'gbp_location_id' => $location->id,
            'author_name' => 'Asha',
            'rating' => 2,
            'comment' => 'The staff were rude and I waited too long.',
        ]);

        $this->postJson("/api/v1/gbp/reviews/{$review->uuid}/suggest")
            ->assertOk()
            ->assertJsonPath('data.source', 'fallback')
            ->assertJsonPath('data.review.sentiment', 'negative')
            ->assertJsonStructure(['data' => ['draft', 'review' => ['draft_reply', 'sentiment']]]);

        $this->assertNotNull($review->fresh()->draft_reply);
    }

    public function test_gbp_overview_returns_rich_dashboard_payload(): void
    {
        $owner = $this->ownerWithPlan();
        $this->actingAs($owner, 'api');

        $location = GbpLocation::create(['name' => 'Test Store', 'tenant_id' => $owner->tenant_id]);
        GbpReview::create([
            'tenant_id' => $owner->tenant_id, 'gbp_location_id' => $location->id,
            'author_name' => 'Asha', 'rating' => 5, 'comment' => 'Great service!',
            'reviewed_at' => now()->subDays(3),
        ]);
        GbpReview::create([
            'tenant_id' => $owner->tenant_id, 'gbp_location_id' => $location->id,
            'author_name' => 'Ben', 'rating' => 2, 'comment' => 'Too slow.',
            'reviewed_at' => now()->subMonths(2),
        ]);

        $this->getJson('/api/v1/gbp/overview')
            ->assertOk()
            ->assertJsonPath('data.stats.total_reviews', 2)
            ->assertJsonStructure(['data' => [
                'locations', 'stats' => ['locations', 'total_reviews', 'average_rating', 'unreplied', 'replied', 'rating_distribution'],
                'health' => ['score', 'grade', 'sub_scores', 'actions'],
                'summary' => ['reviews', 'avg_rating', 'posts', 'photos', 'social', 'leads', 'credit_balance', 'plan'],
                'charts' => ['star_dist', 'directory', 'replied', 'unreplied', 'trend_labels', 'trend_avg', 'trend_count', 'trend_resp', 'breakdown', 'avg_response'],
                'recent_reviews',
            ]]);
    }

    public function test_gbp_offer_post_is_scheduled_with_coupon_and_counts(): void
    {
        $owner = $this->ownerWithPlan();
        $this->actingAs($owner, 'api');
        $loc = GbpLocation::create(['name' => 'Test Store', 'tenant_id' => $owner->tenant_id]);

        $this->postJson('/api/v1/gbp/posts', [
            'location' => $loc->uuid,
            'type' => 'OFFER',
            'summary' => '20% off all services this week!',
            'coupon_code' => 'SAVE20',
            'terms' => 'One per customer.',
            'event_start' => now()->addDay()->toDateTimeString(),
            'event_end' => now()->addDays(7)->toDateTimeString(),
            'schedule_at' => now()->addHours(2)->toDateTimeString(),
        ])->assertCreated()
            ->assertJsonPath('data.post.type', 'OFFER')
            ->assertJsonPath('data.post.status', 'SCHEDULED')
            ->assertJsonPath('data.post.coupon_code', 'SAVE20');

        $this->getJson('/api/v1/gbp/posts')
            ->assertOk()
            ->assertJsonPath('data.counts.total', 1)
            ->assertJsonPath('data.counts.scheduled', 1)
            ->assertJsonPath('data.counts.published', 0);
    }

    public function test_gbp_event_post_defaults_to_draft_without_schedule(): void
    {
        $owner = $this->ownerWithPlan();
        $this->actingAs($owner, 'api');
        $loc = GbpLocation::create(['name' => 'Test Store', 'tenant_id' => $owner->tenant_id]);

        $this->postJson('/api/v1/gbp/posts', [
            'location' => $loc->uuid, 'type' => 'EVENT',
            'summary' => 'Grand opening celebration',
            'event_start' => now()->addDays(3)->toDateTimeString(),
        ])->assertCreated()
            ->assertJsonPath('data.post.type', 'EVENT')
            ->assertJsonPath('data.post.status', 'DRAFT');
    }

    public function test_gbp_post_caption_generates_text(): void
    {
        $owner = $this->ownerWithPlan();
        $this->actingAs($owner, 'api');
        GbpLocation::create(['name' => 'Test Store', 'tenant_id' => $owner->tenant_id]);

        $this->postJson('/api/v1/gbp/posts/generate', ['topic' => 'Diwali discount'])
            ->assertOk()
            ->assertJsonPath('data.source', 'fallback')
            ->assertJsonStructure(['data' => ['text', 'source']]);
    }
}
