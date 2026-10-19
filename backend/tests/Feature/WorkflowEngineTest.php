<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsappPhoneNumber;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Modules\Automation\Services\WorkflowDispatcher;
use App\Modules\Automation\Services\WorkflowEngine;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkflowEngineTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId;
    private WhatsappPhoneNumber $phone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlanSeeder::class);

        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.TEST']]], 200)]);

        $token = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Owner', 'email' => 'owner@acme.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->json('data.token');

        $this->tenantId = User::withoutGlobalScopes()->where('email', 'owner@acme.test')->value('tenant_id');

        Subscription::create([
            'tenant_id' => $this->tenantId,
            'plan_id' => Plan::where('slug', 'enterprise')->value('id'),
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/whatsapp/connect-manual', [
                'waba_id' => '100000000000001',
                'phone_number_id' => '200000000000001',
                'display_phone_number' => '+91 98765 43210',
                'access_token' => 'EAAG-demo-system-user-token-abcdefghij',
            ])->assertCreated();

        $this->phone = WhatsappPhoneNumber::withoutGlobalScopes()->firstOrFail();
    }

    /** Build an active workflow: trigger -> [nodes...] with simple default edges. */
    private function activeWorkflow(string $triggerType, array $actionNodes, array $triggerConfig = []): Workflow
    {
        $wf = Workflow::create([
            'tenant_id' => $this->tenantId, 'name' => 'Test flow',
            'trigger_type' => $triggerType, 'trigger_config' => $triggerConfig ?: null,
            'status' => 'draft', 'version' => 1,
        ]);

        // Entry trigger node.
        $keys = ['trigger'];
        $wf->nodes()->create([
            'tenant_id' => $this->tenantId, 'node_key' => 'trigger', 'family' => 'trigger',
            'type' => $triggerType, 'config' => [], 'position_x' => 0, 'position_y' => 0, 'is_entry' => true,
        ]);

        foreach ($actionNodes as $i => $node) {
            $key = "n{$i}";
            $wf->nodes()->create([
                'tenant_id' => $this->tenantId, 'node_key' => $key,
                'family' => $node['family'], 'type' => $node['type'], 'config' => $node['config'] ?? [],
                'position_x' => 0, 'position_y' => ($i + 1) * 100, 'is_entry' => false,
            ]);
            $keys[] = $key;
        }

        // Chain them: trigger -> n0 -> n1 -> ...
        for ($i = 0; $i < count($keys) - 1; $i++) {
            $wf->edges()->create([
                'tenant_id' => $this->tenantId,
                'source_node_key' => $keys[$i], 'target_node_key' => $keys[$i + 1],
                'branch' => 'default',
            ]);
        }

        $wf->update(['status' => 'active', 'activated_at' => now()]);

        return $wf->fresh(['nodes', 'edges']);
    }

    private function contactWithConversation(string $wa = '919000000001'): array
    {
        $contact = Contact::create([
            'tenant_id' => $this->tenantId, 'wa_id' => $wa,
            'phone' => "+{$wa}", 'name' => 'Jay', 'source' => 'inbound',
        ]);
        $conv = Conversation::create([
            'tenant_id' => $this->tenantId, 'contact_id' => $contact->id,
            'whatsapp_phone_number_id' => $this->phone->id, 'status' => 'open',
        ]);

        return [$contact, $conv];
    }

    public function test_incoming_message_workflow_sends_a_message_and_adds_a_tag(): void
    {
        $this->activeWorkflow('incoming_message', [
            ['family' => 'action', 'type' => 'send_message', 'config' => ['message' => 'Hi {{name}}!']],
            ['family' => 'action', 'type' => 'add_tag', 'config' => ['tag' => 'engaged']],
        ]);

        [$contact, $conv] = $this->contactWithConversation();

        app(WorkflowDispatcher::class)->fire($this->tenantId, 'incoming_message', $contact, $conv, ['body' => 'hello']);

        // A real outbound message was logged...
        $out = Message::withoutGlobalScopes()->where('conversation_id', $conv->id)
            ->where('direction', Message::DIRECTION_OUTBOUND)->first();
        $this->assertNotNull($out);
        $this->assertSame('Hi Jay!', $out->body);

        // ...the tag was added...
        $this->assertStringContainsString('engaged', (string) $contact->fresh()->tag_list);

        // ...and the run completed.
        $exec = WorkflowExecution::withoutGlobalScopes()->where('contact_id', $contact->id)->first();
        $this->assertSame('completed', $exec->status);
    }

    public function test_keyword_trigger_only_fires_on_a_match(): void
    {
        $this->activeWorkflow('keyword', [
            ['family' => 'action', 'type' => 'add_tag', 'config' => ['tag' => 'priced']],
        ], ['keyword' => 'price']);

        [$contact, $conv] = $this->contactWithConversation();

        app(WorkflowDispatcher::class)->fire($this->tenantId, 'keyword', $contact, $conv, ['body' => 'just saying hi']);
        $this->assertNull($contact->fresh()->tag_list);

        app(WorkflowDispatcher::class)->fire($this->tenantId, 'keyword', $contact, $conv, ['body' => 'what is the PRICE?']);
        $this->assertStringContainsString('priced', (string) $contact->fresh()->tag_list);
    }

    public function test_condition_gates_the_branch(): void
    {
        // has-tag? -> (true) add_tag "vip-confirmed"
        $this->activeWorkflow('incoming_message', [
            ['family' => 'condition', 'type' => 'condition_has_tag', 'config' => ['tag' => 'vip']],
            ['family' => 'action', 'type' => 'add_tag', 'config' => ['tag' => 'vip-confirmed']],
        ]);

        // Contact WITHOUT the vip tag — condition false, action skipped.
        [$cold, $coldConv] = $this->contactWithConversation('919000000011');
        app(WorkflowDispatcher::class)->fire($this->tenantId, 'incoming_message', $cold, $coldConv, ['body' => 'hi']);
        $this->assertStringNotContainsString('vip-confirmed', (string) $cold->fresh()->tag_list);

        // Contact WITH the vip tag — condition true, action runs.
        [$vip, $vipConv] = $this->contactWithConversation('919000000012');
        $vip->update(['tag_list' => 'vip']);
        app(WorkflowDispatcher::class)->fire($this->tenantId, 'incoming_message', $vip, $vipConv, ['body' => 'hi']);
        $this->assertStringContainsString('vip-confirmed', (string) $vip->fresh()->tag_list);
    }

    public function test_wait_node_pauses_then_resumes(): void
    {
        $this->activeWorkflow('incoming_message', [
            ['family' => 'wait', 'type' => 'wait', 'config' => ['minutes' => 60]],
            ['family' => 'action', 'type' => 'add_tag', 'config' => ['tag' => 'after-wait']],
        ]);

        [$contact, $conv] = $this->contactWithConversation();
        app(WorkflowDispatcher::class)->fire($this->tenantId, 'incoming_message', $contact, $conv, ['body' => 'hi']);

        // Parked, not yet tagged.
        $exec = WorkflowExecution::withoutGlobalScopes()->where('contact_id', $contact->id)->first();
        $this->assertSame('waiting', $exec->status);
        $this->assertStringNotContainsString('after-wait', (string) $contact->fresh()->tag_list);

        // Pretend the wait elapsed and the scheduler picked it up.
        $exec->update(['resume_at' => now()->subMinute()]);
        $this->artisan('workflows:run')->assertSuccessful();

        $this->assertSame('completed', $exec->fresh()->status);
        $this->assertStringContainsString('after-wait', (string) $contact->fresh()->tag_list);
    }

    public function test_wait_for_reply_resumes_on_the_next_inbound(): void
    {
        $this->activeWorkflow('incoming_message', [
            ['family' => 'wait', 'type' => 'wait_for_reply', 'config' => []],
            ['family' => 'action', 'type' => 'add_tag', 'config' => ['tag' => 'replied']],
        ]);

        [$contact, $conv] = $this->contactWithConversation();
        app(WorkflowDispatcher::class)->fire($this->tenantId, 'incoming_message', $contact, $conv, ['body' => 'start']);

        $exec = WorkflowExecution::withoutGlobalScopes()->where('contact_id', $contact->id)->first();
        $this->assertSame('waiting', $exec->status);
        $this->assertTrue((bool) $exec->waiting_for_reply);

        // The customer replies — the dispatcher resumes the parked run.
        app(WorkflowDispatcher::class)->resumeOnReply($contact->fresh(), $conv->fresh(), 'yes please');

        $this->assertSame('completed', $exec->fresh()->status);
        $this->assertStringContainsString('replied', (string) $contact->fresh()->tag_list);
    }
}
