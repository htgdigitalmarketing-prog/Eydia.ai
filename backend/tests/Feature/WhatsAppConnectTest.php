<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsappBusinessAccount;
use App\Models\WhatsappPhoneNumber;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppConnectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlanSeeder::class);
    }

    /** Register a tenant owner on an active plan and return their bearer token. */
    private function owner(string $email = 'owner@acme.test'): string
    {
        $token = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Owner', 'email' => $email,
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->json('data.token');

        Subscription::create([
            'tenant_id' => User::withoutGlobalScopes()->where('email', $email)->value('tenant_id'),
            'plan_id' => Plan::where('slug', 'enterprise')->value('id'),
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        return $token;
    }

    public function test_tenant_without_a_plan_cannot_use_whatsapp(): void
    {
        $token = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'NoPlan', 'name' => 'Owner', 'email' => 'noplan@acme.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/whatsapp/connect-manual', $this->payload())
            ->assertStatus(403)
            ->assertJsonPath('code', 'plan_required');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/billing/plans')
            ->assertOk();

        $this->assertSame(0, WhatsappPhoneNumber::withoutGlobalScopes()->count());
    }

    public function test_expired_plan_blocks_whatsapp(): void
    {
        $token = $this->owner();
        Subscription::withoutGlobalScopes()->update(['current_period_end' => now()->subDay()]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/whatsapp/numbers')
            ->assertStatus(403)
            ->assertJsonPath('code', 'plan_required');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'waba_id' => '100000000000001',
            'phone_number_id' => '200000000000001',
            'display_phone_number' => '+91 98765 43210',
            'access_token' => 'EAAG-demo-system-user-token-abcdefghij',
            'name' => 'Acme WABA',
        ], $overrides);
    }

    public function test_owner_can_connect_a_number_manually(): void
    {
        $token = $this->owner();

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/whatsapp/connect-manual', $this->payload());

        $res->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.number.display_phone_number', '+91 98765 43210')
            ->assertJsonPath('data.number.is_default', true);

        $this->assertSame(1, WhatsappBusinessAccount::withoutGlobalScopes()->count());
        $this->assertSame(1, WhatsappPhoneNumber::withoutGlobalScopes()->count());

        // Token is encrypted at rest and never returned to the client.
        $this->assertStringNotContainsString('EAAG-demo', json_encode($res->json()));
        $stored = WhatsappBusinessAccount::withoutGlobalScopes()->first();
        $raw = $stored->getRawOriginal('access_token');
        $this->assertNotSame($this->payload()['access_token'], $raw); // stored ciphertext
        $this->assertSame($this->payload()['access_token'], $stored->access_token); // decrypts back
    }

    public function test_numbers_endpoint_lists_connected_numbers(): void
    {
        $token = $this->owner();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/whatsapp/connect-manual', $this->payload())->assertCreated();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/whatsapp/numbers')
            ->assertOk()
            ->assertJsonCount(1, 'data.numbers')
            ->assertJsonPath('data.numbers.0.phone_number_id', '200000000000001');
    }

    public function test_duplicate_phone_number_is_rejected(): void
    {
        $token = $this->owner();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/whatsapp/connect-manual', $this->payload())->assertCreated();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/whatsapp/connect-manual', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone_number_id');
    }

    public function test_numbers_are_isolated_between_tenants(): void
    {
        $tokenA = $this->owner('a@acme.test');
        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->postJson('/api/v1/whatsapp/connect-manual', $this->payload())->assertCreated();

        $tokenB = $this->owner('b@beta.test');
        $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->getJson('/api/v1/whatsapp/numbers')
            ->assertOk()
            ->assertJsonCount(0, 'data.numbers');
    }

    public function test_sync_pulls_metadata_from_provider(): void
    {
        $token = $this->owner();
        $connect = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/whatsapp/connect-manual', $this->payload())->json('data.number');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/whatsapp/numbers/{$connect['id']}/sync")
            ->assertOk()
            ->assertJsonPath('data.number.quality_rating', 'GREEN');
    }

    public function test_owner_can_send_a_test_message(): void
    {
        $token = $this->owner();
        $number = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/whatsapp/connect-manual', $this->payload())->json('data.number');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/whatsapp/numbers/{$number['id']}/send-test", [
                'to' => '91 98765 43210',
                'type' => 'template',
                'template' => 'hello_world',
                'language' => 'en_US',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.to', '919876543210') // normalized to digits
            ->assertJsonStructure(['data' => ['wamid']]);
    }

    public function test_agent_without_permission_cannot_connect(): void
    {
        $token = $this->owner();

        // Create an agent in the same tenant.
        $agent = User::withoutGlobalScopes()->where('email', 'owner@acme.test')->first();
        $agentUser = User::factory()->forTenant($agent->tenant)->create();
        $agentUser->assignRole('agent');
        $agentToken = auth('api')->login($agentUser);

        $this->withHeader('Authorization', "Bearer {$agentToken}")
            ->postJson('/api/v1/whatsapp/connect-manual', $this->payload())
            ->assertStatus(403);
    }
}
