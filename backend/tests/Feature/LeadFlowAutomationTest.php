<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\LeadFlowTask;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsappPhoneNumber;
use App\Modules\Chatbot\Services\ChatbotEngine;
use App\Modules\Chatbot\Services\GbpLeadFlowInstaller;
use App\Modules\Chatbot\Services\LeadFlowAutomation;
use App\Modules\WhatsApp\Services\WhatsAppMessageService;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The time-based half of the GBP Lead Automation funnel: a tapped branch
 * schedules drip follow-ups, a customer reply resets the clock, a human handoff
 * pauses everything, and the scheduler sends what is due.
 */
class LeadFlowAutomationTest extends TestCase
{
    use RefreshDatabase;

    private int $tid;
    private WhatsappPhoneNumber $phone;
    private Contact $contact;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlanSeeder::class);

        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Owner', 'email' => 'owner@acme.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertCreated();
        $owner = User::withoutGlobalScopes()->where('email', 'owner@acme.test')->first();
        $this->tid = $owner->tenant_id;
        Subscription::create([
            'tenant_id' => $this->tid, 'plan_id' => Plan::where('slug', 'enterprise')->value('id'),
            'status' => 'active', 'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        ]);

        app(GbpLeadFlowInstaller::class)->install($this->tid);

        // No real Cloud API calls — the send seam is faked.
        $this->mock(WhatsAppMessageService::class, function ($m) {
            $m->shouldReceive('sendButtons')->andReturn([]);
            $m->shouldReceive('sendText')->andReturn([]);
            $m->shouldReceive('sendTemplate')->andReturn([]);
            $m->shouldReceive('wamid')->andReturn(null);
        });

        $this->phone = WhatsappPhoneNumber::factory()->create(['tenant_id' => $this->tid]);
        $this->contact = Contact::factory()->create([
            'tenant_id' => $this->tid, 'phone' => '+919812300055', 'wa_id' => '919812300055', 'name' => 'Ravi',
        ]);
        $this->conversation = Conversation::factory()->create([
            'tenant_id' => $this->tid, 'contact_id' => $this->contact->id, 'whatsapp_phone_number_id' => $this->phone->id,
        ]);
    }

    private function tap(string $button): void
    {
        app(ChatbotEngine::class)->handleInbound(
            $this->phone, $this->contact->fresh(), $this->conversation->fresh(), 'interactive', $button
        );
    }

    private function pending(string $kind): ?LeadFlowTask
    {
        return LeadFlowTask::withoutGlobalScopes()
            ->where('conversation_id', $this->conversation->id)
            ->where('kind', $kind)
            ->where('status', 'pending')
            ->first();
    }

    public function test_qualifying_schedules_a_nudge_and_a_drip(): void
    {
        $this->tap('Check GBP score');

        $nudge = $this->pending('nudge');
        $drip = $this->pending('drip');

        $this->assertNotNull($nudge, 'a 24h nudge should be scheduled');
        $this->assertNotNull($drip, 'a 3-day drip should be scheduled');
        $this->assertEqualsWithDelta(now()->addDay()->timestamp, $nudge->due_at->timestamp, 120);
        $this->assertEqualsWithDelta(now()->addDays(3)->timestamp, $drip->due_at->timestamp, 120);
    }

    public function test_a_customer_reply_resets_the_clock(): void
    {
        $this->tap('Check GBP score');
        $this->assertNotNull($this->pending('nudge'));

        // The customer replies with their business name — pending nudge/drip drop.
        app(ChatbotEngine::class)->handleInbound(
            $this->phone, $this->contact->fresh(), $this->conversation->fresh(), 'text', 'Acme Dental, Mumbai'
        );

        $this->assertNull($this->pending('nudge'), 'the reply should cancel the nudge');
        $this->assertNull($this->pending('drip'), 'the reply should cancel the drip');
        $this->assertSame(2, LeadFlowTask::withoutGlobalScopes()->where('status', 'cancelled')->count());
    }

    public function test_human_handoff_cancels_everything_pending(): void
    {
        $this->tap('Check GBP score');
        $this->assertSame(2, LeadFlowTask::withoutGlobalScopes()->where('status', 'pending')->count());

        $this->tap('Talk to a human');

        $this->assertSame(0, LeadFlowTask::withoutGlobalScopes()->where('status', 'pending')->count());
    }

    public function test_details_and_cold_branches_schedule_their_own_drip(): void
    {
        $this->tap('Just send details');
        $this->assertNotNull($this->pending('info_followup'));

        $this->tap('Book free demo');
        $this->assertNotNull($this->pending('demo_reminder'));

        $this->tap('Not now');
        $this->assertNotNull($this->pending('cold_reengage'));
    }

    public function test_typed_scenarios_route_to_the_right_branch_and_drip(): void
    {
        // Price objection — ROI branch, not the plain price list.
        app(ChatbotEngine::class)->handleInbound($this->phone, $this->contact->fresh(), $this->conversation->fresh(), 'text', 'honestly this looks too expensive for us');
        $this->assertNotNull($this->pending('objection_followup'));

        // Already using a competitor.
        app(ChatbotEngine::class)->handleInbound($this->phone, $this->contact->fresh(), $this->conversation->fresh(), 'text', "we're already using Grexa for this");
        $this->assertNotNull($this->pending('competitor_followup'));

        // Referral — fast track.
        app(ChatbotEngine::class)->handleInbound($this->phone, $this->contact->fresh(), $this->conversation->fresh(), 'text', 'I was referred by a friend');
        $this->assertNotNull($this->pending('referral_reminder'));

        // Reschedule / no-show.
        app(ChatbotEngine::class)->handleInbound($this->phone, $this->contact->fresh(), $this->conversation->fresh(), 'text', 'sorry I missed my demo, can we reschedule');
        $this->assertNotNull($this->pending('rebook_reminder'));
    }

    public function test_multi_location_hands_off_and_tags_enterprise(): void
    {
        app(ChatbotEngine::class)->handleInbound($this->phone, $this->contact->fresh(), $this->conversation->fresh(), 'text', 'we have multiple locations across the city');

        $lead = \App\Models\Lead::withoutGlobalScopes()->where('tenant_id', $this->tid)->where('phone', '+919812300055')->first();
        $this->assertNotNull($lead);
        $this->assertSame('Enterprise Lead', $lead->meta['tag'] ?? null);
        // A handoff branch pauses the drip — nothing left pending.
        $this->assertSame(0, LeadFlowTask::withoutGlobalScopes()->where('status', 'pending')->count());
    }

    public function test_marking_a_lead_won_schedules_the_review_cadence(): void
    {
        // Give the lead-flow bot a WhatsApp lead tied to this conversation.
        $lead = \App\Models\Lead::withoutGlobalScopes()->create([
            'tenant_id' => $this->tid, 'name' => 'Ravi', 'phone' => '+919812300055',
            'source' => 'WHATSAPP', 'stage' => 'APPOINTMENT',
        ]);

        app(LeadFlowAutomation::class)->scheduleForLeadStage($lead->fresh()->forceFill(['stage' => 'CONVERTED']));

        $task = LeadFlowTask::withoutGlobalScopes()->where('kind', 'review_ask')->where('status', 'pending')->first();
        $this->assertNotNull($task, 'a 30-day review/referral ask should be scheduled');
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $task->due_at->timestamp, 180);
    }

    public function test_leadflow_status_exposes_the_playbook_to_the_allowed_email(): void
    {
        config(['features.lead_flow_emails' => ['owner@acme.test']]);
        $owner = User::withoutGlobalScopes()->where('email', 'owner@acme.test')->first();

        $this->actingAs($owner, 'api')
            ->getJson('/api/v1/chatbots/lead-flow')
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonStructure(['data' => ['playbook' => ['scenarios', 'cadence', 'marketing', 'ads', 'competitive']]]);
    }

    public function test_scheduler_sends_due_tasks_and_records_them(): void
    {
        $this->tap('Check GBP score');

        // Make the nudge due in the past; the drip stays in the future.
        LeadFlowTask::withoutGlobalScopes()->where('kind', 'nudge')
            ->update(['due_at' => now()->subMinutes(5)]);

        $sent = app(LeadFlowAutomation::class)->sendDue();

        $this->assertSame(1, $sent);
        $this->assertSame('sent', LeadFlowTask::withoutGlobalScopes()->where('kind', 'nudge')->value('status'));
        $this->assertSame('pending', LeadFlowTask::withoutGlobalScopes()->where('kind', 'drip')->value('status'));
        $this->assertSame(1, Message::withoutGlobalScopes()
            ->where('conversation_id', $this->conversation->id)
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('body', 'like', '%free Google Business Profile score%')->count());
    }
}
