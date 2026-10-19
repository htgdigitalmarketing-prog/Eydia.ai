<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsappPhoneNumber;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlanSeeder::class);
    }

    /** Register a tenant owner on an active plan; return the owner User. */
    private function ownerWithPlan(string $company, string $email): User
    {
        $this->postJson('/api/v1/auth/register', [
            'company_name' => $company, 'name' => 'Owner', 'email' => $email,
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertCreated();

        $owner = User::withoutGlobalScopes()->where('email', $email)->first();
        Subscription::create([
            'tenant_id' => $owner->tenant_id,
            'plan_id' => Plan::where('slug', 'enterprise')->value('id'),
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        return $owner;
    }

    private function makeConversation(int $tenantId, int $phoneId, ?int $agentId = null): Conversation
    {
        $contact = Contact::factory()->create(['tenant_id' => $tenantId]);

        return Conversation::factory()->create([
            'tenant_id' => $tenantId,
            'contact_id' => $contact->id,
            'whatsapp_phone_number_id' => $phoneId,
            'assigned_agent_id' => $agentId,
        ]);
    }

    public function test_question_is_required(): void
    {
        $owner = $this->ownerWithPlan('Acme', 'owner@acme.test');

        $this->actingAs($owner, 'api')
            ->postJson('/api/v1/assistant/ask', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('question');
    }

    public function test_snapshot_only_counts_the_users_own_tenant(): void
    {
        $ownerA = $this->ownerWithPlan('Acme', 'a@acme.test');
        $ownerB = $this->ownerWithPlan('Beta', 'b@beta.test');

        // Seed each business as its own owner so rows are stamped to the right tenant.
        $this->actingAs($ownerA, 'api');
        $phoneA = WhatsappPhoneNumber::factory()->create(['tenant_id' => $ownerA->tenant_id]);
        $this->makeConversation($ownerA->tenant_id, $phoneA->id);
        $this->makeConversation($ownerA->tenant_id, $phoneA->id);

        $this->actingAs($ownerB, 'api');
        $phoneB = WhatsappPhoneNumber::factory()->create(['tenant_id' => $ownerB->tenant_id]);
        $this->makeConversation($ownerB->tenant_id, $phoneB->id);
        $this->makeConversation($ownerB->tenant_id, $phoneB->id);
        $this->makeConversation($ownerB->tenant_id, $phoneB->id);

        $this->actingAs($ownerA, 'api')
            ->postJson('/api/v1/assistant/ask', ['question' => 'How many conversations and contacts do I have?'])
            ->assertOk()
            ->assertJsonPath('data.snapshot.scope', 'whole_business')
            ->assertJsonPath('data.snapshot.total_conversations', 2)
            ->assertJsonPath('data.snapshot.total_contacts', 2)
            ->assertJsonStructure(['data' => ['answer', 'snapshot']]);
    }

    public function test_agent_snapshot_covers_only_assigned_chats(): void
    {
        $owner = $this->ownerWithPlan('Acme', 'owner@acme.test');

        $agent = User::factory()->forTenant($owner->tenant)->create();
        $agent->assignRole('agent');

        $this->actingAs($owner, 'api');
        $phone = WhatsappPhoneNumber::factory()->create(['tenant_id' => $owner->tenant_id]);
        $this->makeConversation($owner->tenant_id, $phone->id, $agent->id); // assigned
        $this->makeConversation($owner->tenant_id, $phone->id, null);       // not assigned

        $this->actingAs($agent, 'api')
            ->postJson('/api/v1/assistant/ask', ['question' => 'How many chats do I have?'])
            ->assertOk()
            ->assertJsonPath('data.snapshot.scope', 'assigned_chats_only')
            ->assertJsonPath('data.snapshot.total_conversations', 1);
    }

    public function test_assistant_needs_an_active_plan(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'NoPlan', 'name' => 'Owner', 'email' => 'np@acme.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertCreated();
        $owner = User::withoutGlobalScopes()->where('email', 'np@acme.test')->first();

        $this->actingAs($owner, 'api')
            ->postJson('/api/v1/assistant/ask', ['question' => 'hi'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'plan_required');
    }
}
