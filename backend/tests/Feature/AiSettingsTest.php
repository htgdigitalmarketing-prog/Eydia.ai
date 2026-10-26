<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\User;
use App\Modules\Chatbot\Services\AiReplyService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Super Admin → Settings → AI Provider: choose one active provider (Gemini /
 * Anthropic / OpenAI), set its key + model, activate and test. Keys are never
 * returned to the UI, and the app then uses ONLY the selected provider.
 */
class AiSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function superAdmin(): User
    {
        $u = new User(['tenant_id' => null, 'name' => 'Root', 'email' => 'root@eydia.test', 'status' => 'active']);
        $u->password = 'secret123';
        $u->is_super_admin = true;
        $u->email_verified_at = now();
        $u->save();

        return $u;
    }

    private function tenantOwner(): User
    {
        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Owner', 'email' => 'owner@acme.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertCreated();

        return User::withoutGlobalScopes()->where('email', 'owner@acme.test')->first();
    }

    public function test_shows_the_three_providers_and_hides_keys(): void
    {
        $this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/ai-settings')
            ->assertOk()
            ->assertJsonPath('data.ai.is_active', false)
            ->assertJsonPath('data.ai.providers.gemini.has_key', false)
            ->assertJsonStructure(['data' => ['ai' => ['providers' => ['gemini', 'anthropic', 'openai']]]]);
    }

    public function test_selecting_and_activating_a_provider_sets_the_key_and_model(): void
    {
        $this->actingAs($this->superAdmin(), 'api')
            ->putJson('/api/v1/admin/ai-settings', [
                'active_provider' => 'openai',
                'is_active' => true,
                'openai_api_key' => 'sk-test-abcd1234EFGH',
                'openai_model' => 'gpt-4o',
            ])
            ->assertOk()
            ->assertJsonPath('data.ai.effective_provider', 'openai')
            ->assertJsonPath('data.ai.providers.openai.has_key', true)
            ->assertJsonPath('data.ai.providers.openai.key_last4', 'EFGH')
            ->assertJsonPath('data.ai.providers.openai.model', 'gpt-4o');

        // The key is stored (encrypted) but never echoed back in full.
        $this->assertSame('sk-test-abcd1234EFGH', AiSetting::current()->openai_api_key);

        // The app now uses ONLY the selected provider.
        $this->assertSame(['openai'], app(AiReplyService::class)->configuredProviders());
    }

    public function test_cannot_activate_a_provider_without_a_key(): void
    {
        $this->actingAs($this->superAdmin(), 'api')
            ->putJson('/api/v1/admin/ai-settings', ['active_provider' => 'anthropic', 'is_active' => true])
            ->assertStatus(422);
    }

    public function test_saving_a_masked_key_keeps_the_existing_one(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin, 'api')->putJson('/api/v1/admin/ai-settings', [
            'active_provider' => 'gemini', 'is_active' => true, 'gemini_api_key' => 'AIza-realkey-1234',
        ])->assertOk();

        // Re-saving with only the masked placeholder must not wipe the key.
        $this->actingAs($admin, 'api')->putJson('/api/v1/admin/ai-settings', [
            'gemini_model' => 'gemini-3.6-flash', 'gemini_api_key' => '••••••••',
        ])->assertOk();

        $this->assertSame('AIza-realkey-1234', AiSetting::current()->gemini_api_key);
    }

    public function test_non_super_admin_is_blocked(): void
    {
        $this->actingAs($this->tenantOwner(), 'api')
            ->getJson('/api/v1/admin/ai-settings')
            ->assertStatus(403);
    }
}
