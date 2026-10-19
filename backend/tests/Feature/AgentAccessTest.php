<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsappPhoneNumber;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An agent has the granular conversations.* / templates.view / analytics.view
 * permissions but NOT whatsapp.view / whatsapp.manage. These endpoints must
 * gate on the granular permissions the agent actually holds.
 */
class AgentAccessTest extends TestCase
{
    use RefreshDatabase;

    private string $agentToken;
    private User $owner;
    private User $agentUser;

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
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $this->agentUser = User::factory()->forTenant($this->owner->tenant)->create();
        $this->agentUser->assignRole('agent');
        $this->agentToken = auth('api')->login($this->agentUser);
    }

    private function agent(): static
    {
        return $this->withHeader('Authorization', "Bearer {$this->agentToken}");
    }

    public function test_agent_can_open_the_inbox(): void
    {
        $this->agent()->getJson('/api/v1/whatsapp/conversations')
            ->assertOk()
            ->assertJsonStructure(['data' => ['conversations', 'meta']]);
    }

    public function test_agent_can_view_templates_and_analytics(): void
    {
        $this->agent()->getJson('/api/v1/templates')->assertOk();
        $this->agent()->getJson('/api/v1/analytics/dashboard')->assertOk();
    }

    public function test_agent_cannot_create_a_template(): void
    {
        $this->agent()->postJson('/api/v1/templates', [
            'name' => 'promo_offer', 'language' => 'en_US', 'category' => 'MARKETING',
            'body' => 'Hello',
        ])->assertStatus(403);
    }

    /** Create one chat assigned to the agent and one assigned to nobody. */
    private function seedChats(): array
    {
        $phone = WhatsappPhoneNumber::factory()->create(['tenant_id' => $this->owner->tenant_id]);

        $mine = Conversation::factory()->create([
            'tenant_id' => $this->owner->tenant_id,
            'whatsapp_phone_number_id' => $phone->id,
            'assigned_agent_id' => $this->agentUser->id,
        ]);
        $other = Conversation::factory()->create([
            'tenant_id' => $this->owner->tenant_id,
            'whatsapp_phone_number_id' => $phone->id,
            'assigned_agent_id' => null,
        ]);

        return [$mine, $other];
    }

    /** The inbox ORDER BY uses MySQL-only SQL (Contact::hotPrioritySql). */
    private function skipOnSqlite(): void
    {
        if (\Illuminate\Support\Facades\DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('Inbox list ordering uses MySQL-only SQL; verified on the MySQL dev DB.');
        }
    }

    public function test_agent_inbox_lists_only_assigned_chats(): void
    {
        $this->skipOnSqlite();
        [$mine] = $this->seedChats();

        $this->agent()->getJson('/api/v1/whatsapp/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'data.conversations')
            ->assertJsonPath('data.conversations.0.id', $mine->uuid)
            ->assertJsonPath('data.meta.total', 1);
    }

    public function test_owner_inbox_lists_every_chat(): void
    {
        $this->skipOnSqlite();
        $this->seedChats();

        $ownerToken = auth('api')->login($this->owner);
        $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->getJson('/api/v1/whatsapp/conversations')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2);
    }

    public function test_agent_cannot_open_a_chat_assigned_to_someone_else(): void
    {
        [, $other] = $this->seedChats();

        $this->agent()->getJson("/api/v1/whatsapp/conversations/{$other->uuid}/messages")
            ->assertStatus(403);
    }

    public function test_agent_can_open_their_own_chat(): void
    {
        [$mine] = $this->seedChats();

        $this->agent()->getJson("/api/v1/whatsapp/conversations/{$mine->uuid}/messages")
            ->assertOk();
    }
}
