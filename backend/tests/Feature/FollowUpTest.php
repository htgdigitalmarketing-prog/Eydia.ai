<?php

namespace Tests\Feature;

use App\Models\Chatbot;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappPhoneNumber;
use App\Modules\Chatbot\Services\FollowUpService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FollowUpTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId;
    private WhatsappPhoneNumber $phone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);
        $this->tenantId = $tenant->id;
        $this->phone = WhatsappPhoneNumber::factory()->create(['tenant_id' => $tenant->id]);
    }

    private function chatbot(bool $followup): Chatbot
    {
        return Chatbot::create([
            'tenant_id' => $this->tenantId, 'name' => 'Bot', 'is_active' => true,
            'followup_enabled' => $followup, 'followup_message' => 'Aap aage process karna chahte hain?',
        ]);
    }

    private function conversation(int $hoursAgo, int $stage, ?int $agentId = null): Conversation
    {
        $contact = Contact::create([
            'tenant_id' => $this->tenantId, 'wa_id' => '9199' . mt_rand(10000000, 99999999),
            'phone' => '+919999999999', 'name' => 'Cust', 'source' => 'inbound',
        ]);

        return Conversation::create([
            'tenant_id' => $this->tenantId, 'contact_id' => $contact->id,
            'whatsapp_phone_number_id' => $this->phone->id, 'status' => 'open',
            'assigned_agent_id' => $agentId,
            'last_customer_message_at' => now()->subHours($hoursAgo),
            'followup_stage' => $stage,
        ]);
    }

    private function outboundCount(Conversation $c): int
    {
        return Message::withoutGlobalScopes()
            ->where('conversation_id', $c->id)
            ->where('direction', Message::DIRECTION_OUTBOUND)->count();
    }

    public function test_first_followup_sends_after_six_hours(): void
    {
        $this->chatbot(true);
        $c = $this->conversation(hoursAgo: 7, stage: 0);

        app(FollowUpService::class)->sendDue();

        $c->refresh();
        $this->assertSame(1, $c->followup_stage);
        $this->assertSame(1, $this->outboundCount($c));
        $this->assertSame('Aap aage process karna chahte hain?',
            Message::withoutGlobalScopes()->where('conversation_id', $c->id)->latest('id')->value('body'));
    }

    public function test_second_followup_sends_after_twelve_hours(): void
    {
        $this->chatbot(true);
        $c = $this->conversation(hoursAgo: 13, stage: 1);

        app(FollowUpService::class)->sendDue();

        $c->refresh();
        $this->assertSame(2, $c->followup_stage);
        $this->assertSame(1, $this->outboundCount($c));
    }

    public function test_not_due_before_six_hours(): void
    {
        $this->chatbot(true);
        $c = $this->conversation(hoursAgo: 3, stage: 0);

        app(FollowUpService::class)->sendDue();

        $this->assertSame(0, $this->outboundCount($c->refresh()));
    }

    public function test_no_third_followup(): void
    {
        $this->chatbot(true);
        $c = $this->conversation(hoursAgo: 30, stage: 2); // already both sent

        app(FollowUpService::class)->sendDue();

        $this->assertSame(0, $this->outboundCount($c->refresh()));
    }

    public function test_skips_when_assigned_to_an_agent(): void
    {
        $this->chatbot(true);
        $agent = User::factory()->forTenant(Tenant::find($this->tenantId))->create();
        $c = $this->conversation(hoursAgo: 7, stage: 0, agentId: $agent->id);

        app(FollowUpService::class)->sendDue();

        $this->assertSame(0, $this->outboundCount($c->refresh()));
    }

    public function test_skips_when_followups_disabled(): void
    {
        $this->chatbot(false);
        $c = $this->conversation(hoursAgo: 7, stage: 0);

        app(FollowUpService::class)->sendDue();

        $this->assertSame(0, $this->outboundCount($c->refresh()));
    }

    public function test_skips_outside_the_24h_window(): void
    {
        $this->chatbot(true);
        $c = $this->conversation(hoursAgo: 26, stage: 0);

        app(FollowUpService::class)->sendDue();

        $this->assertSame(0, $this->outboundCount($c->refresh()));
    }
}
