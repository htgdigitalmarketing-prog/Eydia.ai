<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\User;
use App\Modules\Mobile\Services\PushNotificationService;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

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
    }

    public function test_app_can_register_a_device(): void
    {
        $this->actingAs($this->owner, 'api')
            ->postJson('/api/v1/devices', [
                'token' => 'ExponentPushToken[abc123]', 'provider' => 'expo', 'platform' => 'android',
            ])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['device' => ['id']]]);

        $this->assertDatabaseHas('device_tokens', [
            'tenant_id' => $this->owner->tenant_id,
            'user_id' => $this->owner->id,
            'token' => 'ExponentPushToken[abc123]',
            'provider' => 'expo',
        ]);
    }

    public function test_re_registering_the_same_token_does_not_duplicate(): void
    {
        $payload = ['token' => 'ExponentPushToken[abc123]'];
        $this->actingAs($this->owner, 'api')->postJson('/api/v1/devices', $payload)->assertCreated();
        $this->actingAs($this->owner, 'api')->postJson('/api/v1/devices', $payload)->assertCreated();

        $this->assertSame(1, DeviceToken::withoutGlobalScopes()->where('token', $payload['token'])->count());
    }

    public function test_list_and_unregister(): void
    {
        $this->actingAs($this->owner, 'api')
            ->postJson('/api/v1/devices', ['token' => 'ExponentPushToken[abc123]'])->assertCreated();

        $this->actingAs($this->owner, 'api')->getJson('/api/v1/devices')
            ->assertOk()->assertJsonCount(1, 'data.devices');

        $this->actingAs($this->owner, 'api')
            ->deleteJson('/api/v1/devices', ['token' => 'ExponentPushToken[abc123]'])->assertOk();

        $this->assertSame(0, DeviceToken::withoutGlobalScopes()->count());
    }

    public function test_push_is_a_safe_noop_without_devices(): void
    {
        Http::fake();

        app(PushNotificationService::class)->notifyTenant($this->owner->tenant_id, 'Title', 'Body');

        Http::assertNothingSent();
    }

    public function test_push_sends_an_expo_message_when_a_device_exists(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => []], 200)]);

        DeviceToken::create([
            'tenant_id' => $this->owner->tenant_id, 'user_id' => $this->owner->id,
            'token' => 'ExponentPushToken[abc123]', 'provider' => 'expo', 'platform' => 'android',
        ]);

        app(PushNotificationService::class)->notifyTenant($this->owner->tenant_id, 'Atul', 'Hello there');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'exp.host')
            && $request[0]['to'] === 'ExponentPushToken[abc123]'
            && $request[0]['title'] === 'Atul');
    }
}
