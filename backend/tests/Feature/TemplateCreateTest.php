<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Template;
use App\Models\TemplateComponent;
use App\Models\User;
use App\Models\WhatsappBusinessAccount;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateCreateTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlanSeeder::class);

        $this->token = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Owner', 'email' => 'owner@acme.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->json('data.token');

        Subscription::create([
            'tenant_id' => User::withoutGlobalScopes()->where('email', 'owner@acme.test')->value('tenant_id'),
            'plan_id' => Plan::where('slug', 'enterprise')->value('id'),
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $this->authed()->postJson('/api/v1/whatsapp/connect-manual', [
            'waba_id' => '100000000000001', 'phone_number_id' => '200000000000001',
            'display_phone_number' => '+91 98765 43210', 'access_token' => 'EAAG-demo-token-abcdefghij',
        ])->assertCreated();
    }

    private function authed(): static
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    public function test_create_a_text_template(): void
    {
        $this->authed()->postJson('/api/v1/templates', [
            'name' => 'order_update', 'language' => 'en', 'category' => 'UTILITY',
            'body' => 'Hi {{1}}, your order is confirmed.', 'body_example' => ['Atul'],
        ])->assertCreated()->assertJsonPath('data.template.name', 'order_update');

        $this->assertSame(1, Template::withoutGlobalScopes()->where('name', 'order_update')->count());
    }

    public function test_image_header_template_requires_a_sample(): void
    {
        $this->authed()->postJson('/api/v1/templates', [
            'name' => 'festive_offer', 'language' => 'en', 'category' => 'MARKETING',
            'header_format' => 'IMAGE', 'body' => 'Hello {{1}}!', 'body_example' => ['Atul'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('header_handle');

        $this->assertSame(0, Template::withoutGlobalScopes()->count());
    }

    public function test_upload_sample_returns_a_handle(): void
    {
        $this->authed()->postJson('/api/v1/templates/upload-sample', [
            'file' => UploadedFile::fake()->image('banner.jpg', 600, 400),
        ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['header_handle']]);
    }

    public function test_create_an_image_header_template_with_a_handle(): void
    {
        $handle = $this->authed()->postJson('/api/v1/templates/upload-sample', [
            'file' => UploadedFile::fake()->image('banner.jpg', 600, 400),
        ])->json('data.header_handle');

        $this->authed()->postJson('/api/v1/templates', [
            'name' => 'festive_offer', 'language' => 'en', 'category' => 'MARKETING',
            'header_format' => 'IMAGE', 'header_handle' => $handle,
            'body' => 'Hello {{1}}!', 'body_example' => ['Atul'],
        ])->assertCreated();

        $header = TemplateComponent::withoutGlobalScopes()->where('type', 'HEADER')->first();
        $this->assertSame('IMAGE', $header->format);
        $this->assertSame([$handle], $header->example['header_handle']);
    }

    /** A template PHONE_NUMBER button is normalised to E.164 (Meta #192). */
    public function test_phone_button_is_normalized_to_e164(): void
    {
        $this->authed()->postJson('/api/v1/templates', [
            'name' => 'call_us', 'language' => 'en', 'category' => 'UTILITY',
            'body' => 'Call us anytime.',
            'buttons' => [['type' => 'PHONE_NUMBER', 'text' => 'Call', 'phone_number' => '09876543210']],
        ])->assertCreated();

        $buttons = TemplateComponent::withoutGlobalScopes()->where('type', 'BUTTONS')->latest('id')->first();
        $this->assertSame('+919876543210', $buttons->buttons[0]['phone_number']);
    }

    /**
     * A tenant that reconnected WhatsApp holds several WABA rows; template create
     * must target the CONNECTED + most-recent one, never a dead/older row.
     */
    public function test_template_targets_connected_latest_waba(): void
    {
        $tenantId = User::withoutGlobalScopes()->where('email', 'owner@acme.test')->value('tenant_id');

        // A newer but DISCONNECTED WABA (a dead reconnect) must be skipped…
        WhatsappBusinessAccount::create([
            'tenant_id' => $tenantId, 'waba_id' => 'DEAD999', 'name' => 'dead',
            'access_token' => 'tok-dead-aaaaaaaaaa', 'status' => 'disconnected', 'connected_at' => now()->addDay(),
        ]);
        // …and a newer CONNECTED WABA must beat the older one from setUp.
        $live = WhatsappBusinessAccount::create([
            'tenant_id' => $tenantId, 'waba_id' => 'LIVE777', 'name' => 'live',
            'access_token' => 'tok-live-bbbbbbbbbb', 'status' => 'connected', 'connected_at' => now()->addDays(2),
        ]);

        $this->authed()->postJson('/api/v1/templates', [
            'name' => 'pick_waba', 'language' => 'en', 'category' => 'UTILITY', 'body' => 'Hi there.',
        ])->assertCreated();

        $tpl = Template::withoutGlobalScopes()->where('name', 'pick_waba')->first();
        $this->assertSame($live->id, $tpl->whatsapp_business_account_id);
    }
}
