<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\DeviceToken;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappBusinessAccount;
use App\Models\WhatsappPhoneNumber;
use App\Modules\Mobile\Services\PushNotificationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * FCM HTTP v1 push — additive, flag-gated. Verifies: flags off = old behavior,
 * v1 payload shape, dead-token pruning, one-bad-token isolation, per-user
 * targeting, Expo unchanged, and that a push failure never throws.
 */
class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $owner;
    private User $manager;
    private User $agent;
    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Owner', 'email' => 'owner@acme.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertCreated();

        $this->owner = User::withoutGlobalScopes()->where('email', 'owner@acme.test')->first();
        $this->tenant = Tenant::withoutGlobalScopes()->find($this->owner->tenant_id);

        $this->manager = User::factory()->forTenant($this->tenant)->create();
        $this->manager->assignRole('manager');
        $this->agent = User::factory()->forTenant($this->tenant)->create();
        $this->agent->assignRole('agent');
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function svc(): PushNotificationService
    {
        return app(PushNotificationService::class);
    }

    private function fcmToken(User $user, string $token): DeviceToken
    {
        return DeviceToken::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'token' => $token, 'provider' => 'fcm', 'platform' => 'android', 'last_used_at' => now(),
        ]);
    }

    /** Enable the v1 path with a working (generated) service-account key. */
    private function enableV1(): void
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privateKey);
        $path = tempnam(sys_get_temp_dir(), 'fcm') . '.json';
        file_put_contents($path, json_encode([
            'type' => 'service_account', 'project_id' => 'eydia-55158',
            'client_email' => 'test@eydia-55158.iam.gserviceaccount.com', 'private_key' => $privateKey,
        ]));
        $this->tmp[] = $path;
        config(['services.fcm.v1_enabled' => true, 'services.fcm.credentials_path' => $path]);
    }

    private function fakeV1Ok(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'oauth2.googleapis.com')) {
                return Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600], 200);
            }

            return Http::response(['name' => 'projects/eydia-55158/messages/1'], 200);
        });
    }

    private function conversation(?int $agentId): Conversation
    {
        $waba = WhatsappBusinessAccount::create([
            'tenant_id' => $this->tenant->id, 'waba_id' => 'WABA1', 'name' => 'w',
            'access_token' => 'tok-aaaaaaaaaa', 'status' => 'connected',
        ]);
        $phone = WhatsappPhoneNumber::create([
            'tenant_id' => $this->tenant->id, 'whatsapp_business_account_id' => $waba->id,
            'phone_number_id' => 'PN1', 'display_phone_number' => '+1', 'status' => 'connected',
        ]);
        $contact = Contact::create(['tenant_id' => $this->tenant->id, 'wa_id' => '91999', 'phone' => '+91999']);

        return Conversation::create([
            'tenant_id' => $this->tenant->id, 'contact_id' => $contact->id,
            'whatsapp_phone_number_id' => $phone->id, 'assigned_agent_id' => $agentId, 'status' => 'open',
        ]);
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    public function test_flag_off_makes_no_fcm_call(): void
    {
        Http::fake();
        $this->fcmToken($this->owner, 'TKN'); // flag off, no legacy key → no call
        $this->svc()->notifyTenant($this->tenant->id, 'Hi', 'There');
        Http::assertNothingSent();
    }

    public function test_flag_on_but_no_credentials_skips_silently(): void
    {
        config(['services.fcm.v1_enabled' => true, 'services.fcm.credentials_path' => '/no/such/file.json']);
        Http::fake();
        $this->fcmToken($this->owner, 'TKN');
        $this->svc()->notifyTenant($this->tenant->id, 'Hi', 'There'); // must not throw
        Http::assertNothingSent();
    }

    public function test_v1_payload_shape_with_string_data(): void
    {
        $this->enableV1();
        $this->fakeV1Ok();
        $this->fcmToken($this->owner, 'TKN');

        $this->svc()->notifyTenant($this->tenant->id, 'Title', str_repeat('x', 200), [
            'type' => 'whatsapp_message', 'conversation_id' => 'uuid-123', 'count' => 5,
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'messages:send')) {
                return false;
            }
            $m = $request->data()['message'];
            return $m['token'] === 'TKN'
                && $m['notification']['title'] === 'Title'
                && mb_strlen($m['notification']['body']) <= 123            // truncated to ~120 (+ "...")
                && str_starts_with($m['notification']['body'], str_repeat('x', 100))
                && $m['data']['conversation_id'] === 'uuid-123'
                && $m['data']['count'] === '5'                              // all data are strings
                && $m['android']['priority'] === 'HIGH'
                && $m['android']['collapse_key'] === 'uuid-123'
                && $m['apns']['headers']['apns-collapse-id'] === 'uuid-123';
        });
    }

    public function test_dead_token_is_deleted(): void
    {
        $this->enableV1();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'oauth2.googleapis.com')) {
                return Http::response(['access_token' => 'ya29.test'], 200);
            }

            return Http::response(['error' => ['status' => 'UNREGISTERED']], 404);
        });
        $this->fcmToken($this->owner, 'DEAD');

        $this->svc()->notifyTenant($this->tenant->id, 'Hi', 'There');

        $this->assertDatabaseMissing('device_tokens', ['token' => 'DEAD']);
    }

    public function test_one_bad_token_does_not_block_the_others(): void
    {
        $this->enableV1();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'oauth2.googleapis.com')) {
                return Http::response(['access_token' => 'ya29.test'], 200);
            }
            $token = $request->data()['message']['token'] ?? '';

            return $token === 'DEAD'
                ? Http::response(['error' => ['status' => 'UNREGISTERED']], 404)
                : Http::response(['name' => 'ok'], 200);
        });
        $this->fcmToken($this->owner, 'DEAD');
        $this->fcmToken($this->manager, 'GOOD');

        $this->svc()->notifyTenant($this->tenant->id, 'Hi', 'There');

        $this->assertDatabaseMissing('device_tokens', ['token' => 'DEAD']);
        $this->assertDatabaseHas('device_tokens', ['token' => 'GOOD']); // the good one survived
    }

    public function test_targeted_agent_only_gets_assigned_chat(): void
    {
        config(['services.push.targeted' => true]);
        $this->enableV1();
        $this->fakeV1Ok();

        $agent2 = User::factory()->forTenant($this->tenant)->create();
        $agent2->assignRole('agent');

        $this->fcmToken($this->owner, 'OWNER');
        $this->fcmToken($this->manager, 'MANAGER');
        $this->fcmToken($this->agent, 'AGENT');
        $this->fcmToken($agent2, 'AGENT2');

        $conversation = $this->conversation($this->agent->id); // assigned to $this->agent
        $this->svc()->notifyConversation($conversation, 'New', 'msg', ['type' => 'whatsapp_message', 'conversation_id' => $conversation->uuid]);

        $sentTokens = [];
        Http::assertSent(function ($request) use (&$sentTokens) {
            if (str_contains($request->url(), 'messages:send')) {
                $sentTokens[] = $request->data()['message']['token'];
            }
            return true;
        });

        sort($sentTokens);
        $this->assertSame(['AGENT', 'MANAGER', 'OWNER'], $sentTokens); // agent2 (unassigned) excluded
    }

    public function test_expo_path_unchanged_when_flag_off(): void
    {
        Http::fake(fn ($request) => Http::response(['data' => []], 200));
        DeviceToken::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id,
            'token' => 'ExponentPushToken[abc]', 'provider' => 'expo', 'platform' => 'ios', 'last_used_at' => now(),
        ]);

        $this->svc()->notifyTenant($this->tenant->id, 'Hi', 'There');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'exp.host')
            && $request->data()[0]['to'] === 'ExponentPushToken[abc]');
    }

    public function test_push_failure_never_throws(): void
    {
        $this->enableV1();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'oauth2.googleapis.com')) {
                return Http::response(['access_token' => 'ya29.test'], 200);
            }

            return Http::response('boom', 500);
        });
        $this->fcmToken($this->owner, 'TKN');

        $this->svc()->notifyTenant($this->tenant->id, 'Hi', 'There'); // 500s must not surface
        $this->assertDatabaseHas('device_tokens', ['token' => 'TKN']); // a 500 is not "dead"
    }

    public function test_device_registration_caps_at_ten(): void
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'owner@acme.test', 'password' => 'Password123'])
            ->json('data.token');

        for ($i = 0; $i < 12; $i++) {
            $this->withHeader('Authorization', "Bearer {$token}")
                ->postJson('/api/v1/devices', ['token' => "tkn-{$i}", 'provider' => 'fcm', 'platform' => 'android'])
                ->assertCreated();
        }

        $this->assertSame(10, DeviceToken::where('user_id', $this->owner->id)->count());
        // The two oldest were dropped; the newest remain.
        $this->assertDatabaseHas('device_tokens', ['token' => 'tkn-11']);
        $this->assertDatabaseMissing('device_tokens', ['token' => 'tkn-0']);
    }
}
