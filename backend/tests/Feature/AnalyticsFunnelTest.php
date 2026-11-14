<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsappPhoneNumber;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Analytics funnel: counts come from the append-only message_statuses event log
 * (cumulative, like Meta), not the message's single current-status bucket. The
 * key correction: a message that got a delivered/read event still counts as
 * delivered even if a late `failed` webhook later flipped its row.
 */
class AnalyticsFunnelTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private int $tenantId;
    private int $phoneId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlanSeeder::class);

        $this->token = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Owner', 'email' => 'owner@acme.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->json('data.token');

        $this->tenantId = User::withoutGlobalScopes()->where('email', 'owner@acme.test')->value('tenant_id');
        Subscription::create([
            'tenant_id' => $this->tenantId, 'plan_id' => Plan::where('slug', 'enterprise')->value('id'),
            'status' => 'active', 'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/whatsapp/connect-manual', [
                'waba_id' => '100000000000001', 'phone_number_id' => '200000000000001',
                'display_phone_number' => '+91 98765 43210', 'access_token' => 'EAAG-demo-token-abcdefghij',
            ])->assertCreated();
        $this->phoneId = WhatsappPhoneNumber::withoutGlobalScopes()->value('id');
    }

    private function outboundMessage(string $wamid, string $currentStatus): Message
    {
        $contact = Contact::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $this->tenantId, 'wa_id' => '91900000' . substr($wamid, -4)],
            ['phone' => '+91900000' . substr($wamid, -4)],
        );
        $conversation = Conversation::withoutGlobalScopes()->firstOrCreate(
            ['contact_id' => $contact->id, 'whatsapp_phone_number_id' => $this->phoneId],
            ['tenant_id' => $this->tenantId, 'status' => 'open'],
        );

        return Message::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantId, 'conversation_id' => $conversation->id, 'contact_id' => $contact->id,
            'whatsapp_phone_number_id' => $this->phoneId, 'direction' => 'outbound', 'type' => 'template',
            'body' => 'hi', 'external_message_id' => $wamid, 'status' => $currentStatus,
        ]);
    }

    private function logStatus(Message $m, string $status): void
    {
        MessageStatus::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantId, 'message_id' => $m->id, 'status' => $status, 'occurred_at' => now(),
        ]);
    }

    public function test_funnel_counts_from_event_log_not_current_status(): void
    {
        // A: delivered + read, but a late `failed` flipped the row → still delivered & read.
        $a = $this->outboundMessage('wamid-A', 'failed');
        $this->logStatus($a, 'sent');
        $this->logStatus($a, 'delivered');
        $this->logStatus($a, 'read');

        // B: stuck at sent, no delivered event → not delivered.
        $b = $this->outboundMessage('wamid-B', 'sent');
        $this->logStatus($b, 'sent');

        // C: delivered (not read).
        $c = $this->outboundMessage('wamid-C', 'delivered');
        $this->logStatus($c, 'sent');
        $this->logStatus($c, 'delivered');

        $totals = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/v1/analytics/messages?days=30')
            ->assertOk()
            ->json('data.totals');

        $this->assertSame(3, $totals['total_sent']);      // all outbound
        $this->assertSame(2, $totals['total_delivered']); // A + C reached the device
        $this->assertSame(1, $totals['total_read']);      // A
        $this->assertSame(1, $totals['total_failed']);    // 3 sent − 2 delivered
    }
}
