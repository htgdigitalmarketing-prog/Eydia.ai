<?php

namespace Tests\Feature;

use App\Models\GbpLocation;
use App\Models\GbpPost;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\Chatbot\Services\AiReplyService;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The three GBP AI automations: SEO autofill context, AI rewrite of the business
 * description for SEO, and AI-planned posts that schedule themselves.
 */
class GbpAiFeaturesTest extends TestCase
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
            'tenant_id' => $this->owner->tenant_id,
            'name' => 'VezetoLMS',
            'category' => 'Software company',
            'address' => 'A778, Naveen Nagar, Sector 19, Noida, Uttar Pradesh 201301, India',
            'meta' => ['service_area' => ['Noida', 'Delhi'], 'attributes' => ['Amenities' => ['Free Wi-Fi']]],
        ]);

        // Fake the AI: JSON for planning, prose for the rewrite. No real network.
        $this->mock(AiReplyService::class, function ($m) {
            $m->shouldReceive('configuredProviders')->andReturn(['gemini']);
            $m->shouldReceive('generateImage')->andReturn(null);
            $m->shouldReceive('canGenerateImages')->andReturn(false);
            $m->shouldReceive('classify')->andReturnUsing(function (string $system) {
                if (str_contains($system, 'ONLY JSON')) {
                    return '{"posts":[{"title":"Diwali Offer","type":"OFFER","summary":"Big Diwali sale at VezetoLMS! 🎉 Save on your branded learning platform."},'
                        . '{"title":"New feature","type":"STANDARD","summary":"We just shipped live classes and analytics. Ask us how it helps your institute in Noida."}]}';
                }

                return 'VezetoLMS helps institutes and businesses across Noida launch their own branded learning platform — courses, live classes and analytics in one place. Get in touch today!';
            });
        });
    }

    public function test_seo_overview_autofills_context_from_the_profile(): void
    {
        $this->actingAs($this->owner, 'api')
            ->getJson('/api/v1/seo/overview')
            ->assertOk()
            ->assertJsonPath('data.context.business', 'VezetoLMS')
            ->assertJsonPath('data.context.city', 'Noida')
            ->assertJsonPath('data.context.industry', 'Software company');
    }

    public function test_ai_rewrites_the_business_description_for_seo(): void
    {
        $this->actingAs($this->owner, 'api')
            ->postJson('/api/v1/gbp/profile/ai-rewrite', ['keywords' => ['LMS Noida', 'online course platform']])
            ->assertOk()
            ->assertJsonPath('data.source', 'ai')
            ->assertJsonPath('data.description', fn ($v) => str_contains((string) $v, 'VezetoLMS'));
    }

    public function test_ai_plans_and_schedules_a_batch_of_posts(): void
    {
        $res = $this->actingAs($this->owner, 'api')
            ->postJson('/api/v1/gbp/posts/auto-plan', ['count' => 2, 'cadence_days' => 3])
            ->assertCreated()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.source', 'ai');

        $posts = GbpPost::withoutGlobalScopes()->where('tenant_id', $this->owner->tenant_id)->orderBy('scheduled_at')->get();
        $this->assertCount(2, $posts);
        $this->assertTrue($posts->every(fn ($p) => $p->status === 'SCHEDULED'));
        $this->assertTrue($posts->every(fn ($p) => $p->scheduled_at->isFuture()));
        // Spread by the cadence: second post is 3 days after the first.
        $this->assertSame(3, (int) $posts[0]->scheduled_at->diffInDays($posts[1]->scheduled_at));
        $this->assertContains('OFFER', $posts->pluck('type')->all());
        // Every generated post carries SEO hashtags.
        $this->assertTrue($posts->every(fn ($p) => str_contains($p->summary, '#')));
    }
}
