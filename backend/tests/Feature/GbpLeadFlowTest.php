<?php

namespace Tests\Feature;

use App\Models\Chatbot;
use App\Models\ChatbotRule;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsappPhoneNumber;
use App\Modules\Chatbot\Services\ChatbotEngine;
use App\Modules\Chatbot\Services\GbpLeadFlowInstaller;
use App\Modules\WhatsApp\Services\WhatsAppMessageService;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one-click "GBP Lead Automation" WhatsApp funnel: installs a button-driven
 * chatbot and, when a lead taps a quick-reply button, replies and tags the lead
 * in the CRM.
 */
class GbpLeadFlowTest extends TestCase
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

    public function test_install_endpoint_creates_the_funnel(): void
    {
        $owner = $this->owner();
        config(['features.lead_flow_emails' => ['owner@acme.test']]); // allow the test owner

        $this->actingAs($owner, 'api')
            ->postJson('/api/v1/chatbots/lead-flow/install')
            ->assertOk()
            ->assertJsonPath('data.chatbot.is_lead_flow', true)
            ->assertJsonPath('data.chatbot.is_active', true)
            ->assertJsonStructure(['data' => ['chatbot' => ['welcome_buttons', 'rules'], 'steps']]);

        $this->assertSame(1, Chatbot::withoutGlobalScopes()->where('tenant_id', $owner->tenant_id)->where('is_lead_flow', true)->count());
        $bot = Chatbot::withoutGlobalScopes()->where('tenant_id', $owner->tenant_id)->first();
        $this->assertSame(6, ChatbotRule::withoutGlobalScopes()->where('chatbot_id', $bot->id)->count());

        // Idempotent re-install keeps exactly one flow with 6 rules.
        app(GbpLeadFlowInstaller::class)->install($owner->tenant_id);
        $this->assertSame(1, Chatbot::withoutGlobalScopes()->where('tenant_id', $owner->tenant_id)->where('is_lead_flow', true)->count());
        $this->assertSame(6, ChatbotRule::withoutGlobalScopes()->where('chatbot_id', $bot->id)->count());

        $this->getJson('/api/v1/chatbots/lead-flow')->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.installed', true);
    }

    public function test_automation_is_gated_to_allowed_emails(): void
    {
        config(['features.lead_flow_emails' => ['heltogtechnologies@gmail.com']]);
        $owner = $this->owner(); // owner@acme.test — not allowed

        $this->actingAs($owner, 'api')
            ->getJson('/api/v1/chatbots/lead-flow')
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.installed', false);

        $this->actingAs($owner, 'api')
            ->postJson('/api/v1/chatbots/lead-flow/install')
            ->assertStatus(403);
    }

    public function test_button_tap_replies_and_tags_the_lead(): void
    {
        $owner = $this->owner();
        $tid = $owner->tenant_id;
        app(GbpLeadFlowInstaller::class)->install($tid);

        // No real Cloud API calls — the send seam is faked.
        $this->mock(WhatsAppMessageService::class, function ($m) {
            $m->shouldReceive('sendButtons')->andReturn([]);
            $m->shouldReceive('sendText')->andReturn([]);
            $m->shouldReceive('sendTemplate')->andReturn([]);
            $m->shouldReceive('wamid')->andReturn(null);
        });

        $phone = WhatsappPhoneNumber::factory()->create(['tenant_id' => $tid]);
        $contact = Contact::factory()->create(['tenant_id' => $tid, 'phone' => '+919812300055', 'wa_id' => '919812300055', 'name' => 'Ravi']);
        $conversation = Conversation::factory()->create([
            'tenant_id' => $tid, 'contact_id' => $contact->id, 'whatsapp_phone_number_id' => $phone->id,
        ]);

        // Lead taps the "Book free demo" quick-reply button (arrives as interactive).
        app(ChatbotEngine::class)->handleInbound($phone, $contact, $conversation, 'interactive', 'Book free demo');

        $lead = Lead::withoutGlobalScopes()->where('tenant_id', $tid)->where('phone', '+919812300055')->first();
        $this->assertNotNull($lead, 'a WhatsApp lead should be created');
        $this->assertSame('APPOINTMENT', $lead->stage);
        $this->assertSame('WHATSAPP', $lead->source);
        $this->assertSame('Demo Booked', $lead->meta['tag'] ?? null);
    }
}
