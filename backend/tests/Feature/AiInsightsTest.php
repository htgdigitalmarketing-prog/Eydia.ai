<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\User;
use App\Support\AiUsageRecorder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Super-admin AI spend insights + the per-call usage recorder. */
class AiInsightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_recorder_computes_cost_from_pricing(): void
    {
        // gpt-4o-mini = [0.15, 0.60] per 1M tokens.
        // 1,000,000 in + 1,000,000 out = 0.15 + 0.60 = $0.75 = 750,000 micros.
        app(AiUsageRecorder::class)->recordText('openai', 'gpt-4o-mini', 1_000_000, 1_000_000);

        $log = AiUsageLog::firstOrFail();
        $this->assertSame('openai', $log->provider);
        $this->assertSame(750_000, $log->cost_micros);
    }

    public function test_image_cost_is_recorded(): void
    {
        app(AiUsageRecorder::class)->recordImage('gemini', 'gemini-2.5-flash-image', 1);
        // 0.039 USD = 39,000 micros.
        $this->assertSame(39_000, (int) AiUsageLog::where('operation', 'image')->value('cost_micros'));
    }

    public function test_super_admin_sees_provider_and_model_breakdown(): void
    {
        app(AiUsageRecorder::class)->recordText('openai', 'gpt-4o-mini', 1_000_000, 1_000_000);
        app(AiUsageRecorder::class)->recordText('anthropic', 'claude-opus-5', 1_000_000, 0); // $15

        $admin = User::factory()->create(['is_super_admin' => true]);

        $res = $this->actingAs($admin, 'api')->getJson('/api/v1/admin/ai-insights?range=all');
        $res->assertOk();

        $res->assertJsonPath('data.totals.all_time.usd', 15.75);
        $this->assertCount(2, $res->json('data.by_provider'));
        $this->assertNotEmpty($res->json('data.by_model'));
        $this->assertSame('internal_ledger', $res->json('data.source'));
    }

    public function test_non_super_admin_is_forbidden(): void
    {
        $user = User::factory()->create(['is_super_admin' => false]);
        $this->actingAs($user, 'api')->getJson('/api/v1/admin/ai-insights')->assertForbidden();
    }
}
