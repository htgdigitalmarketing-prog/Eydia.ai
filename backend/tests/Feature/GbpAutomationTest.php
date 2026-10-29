<?php

namespace Tests\Feature;

use App\Models\GbpAutomation;
use App\Models\GbpLocation;
use App\Models\GbpPost;
use App\Models\GbpReview;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\Chatbot\Services\AiReplyService;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The GBP automation control centre: ON/OFF toggles, the QR review-assist page,
 * and the background runner that auto-replies to reviews and tops up the post
 * queue when the switches are on.
 */
class GbpAutomationTest extends TestCase
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

        GbpLocation::withoutGlobalScopes()->create([
            'tenant_id' => $this->owner->tenant_id, 'name' => 'VezetoLMS', 'category' => 'Software company',
            'google_location_id' => 'demo-loc-1', 'review_count' => 10,
            'meta' => ['review_url' => 'https://g.page/r/ABCD/review'],
        ]);

        $this->mock(AiReplyService::class, function ($m) {
            $m->shouldReceive('configuredProviders')->andReturn(['gemini']);
            $m->shouldReceive('generateImage')->andReturn(null);
            $m->shouldReceive('classify')->andReturnUsing(function (string $system) {
                if (str_contains($system, 'ONLY JSON')) {
                    return '{"posts":[{"title":"Update","type":"STANDARD","summary":"Fresh update from VezetoLMS!"}]}';
                }
                if (str_contains($system, 'customer')) {
                    return 'Fantastic experience at VezetoLMS — the team was helpful and professional. Highly recommend!';
                }
                return 'Thank you so much for the kind words! We loved having you.';
            });
        });
    }

    public function test_toggles_save_and_persist(): void
    {
        $this->actingAs($this->owner, 'api')
            ->putJson('/api/v1/gbp/automation', [
                'auto_review_replies' => true, 'auto_posts' => true, 'qr_review_assist' => true,
                'post_frequency_days' => 2, 'reply_tone' => 'warm',
            ])
            ->assertOk()
            ->assertJsonPath('data.automation.auto_review_replies', true)
            ->assertJsonPath('data.automation.post_frequency_days', 2);

        // QR assist URL is exposed once the toggle is on and a review link exists.
        $this->actingAs($this->owner, 'api')->getJson('/api/v1/gbp/automation')
            ->assertOk()
            ->assertJsonPath('data.automation.qr_review_assist', true)
            ->assertJsonPath('data.assist_url', fn ($v) => is_string($v) && str_contains($v, '/review/'));
    }

    public function test_public_review_assist_page_and_ai_suggest(): void
    {
        $a = GbpAutomation::forTenant($this->owner->tenant_id);
        $loc = GbpLocation::withoutGlobalScopes()->where('tenant_id', $this->owner->tenant_id)->first();
        $a->forceFill(['gbp_location_id' => $loc->id, 'slug' => 'vezetolms-abc123', 'qr_review_assist' => true])->save();

        $this->get('/review/vezetolms-abc123')
            ->assertOk()
            ->assertSee('VezetoLMS', false);

        $this->postJson('/review/vezetolms-abc123/suggest', ['rating' => 5, 'notes' => 'great staff'])
            ->assertOk()
            ->assertJsonPath('data.review', fn ($v) => str_contains((string) $v, 'VezetoLMS'));
    }

    public function test_runner_auto_replies_to_unanswered_reviews(): void
    {
        GbpAutomation::forTenant($this->owner->tenant_id)->update(['auto_review_replies' => true]);
        $loc = GbpLocation::withoutGlobalScopes()->where('tenant_id', $this->owner->tenant_id)->first();
        $review = GbpReview::withoutGlobalScopes()->create([
            'tenant_id' => $this->owner->tenant_id, 'gbp_location_id' => $loc->id,
            'google_review_id' => 'demo-rev-1', 'author_name' => 'Ravi', 'rating' => 5,
            'comment' => 'Loved it!', 'reviewed_at' => now()->subDay(),
        ]);

        $this->artisan('gbp:run-automations')->assertExitCode(0);

        $review->refresh();
        $this->assertNotNull($review->reply);
        $this->assertNotNull($review->replied_at);
    }

    public function test_runner_tops_up_the_scheduled_post_queue(): void
    {
        GbpAutomation::forTenant($this->owner->tenant_id)->update(['auto_posts' => true, 'post_queue_target' => 2]);

        $this->artisan('gbp:run-automations')->assertExitCode(0);

        $scheduled = GbpPost::withoutGlobalScopes()->where('tenant_id', $this->owner->tenant_id)
            ->where('status', 'SCHEDULED')->get();
        $this->assertGreaterThanOrEqual(1, $scheduled->count());
        $this->assertTrue($scheduled->every(fn ($p) => $p->scheduled_at->isFuture()));
    }
}
