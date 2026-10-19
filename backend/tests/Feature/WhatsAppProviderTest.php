<?php

namespace Tests\Feature;

use App\Models\ApiLog;
use App\Models\Tenant;
use App\Models\WhatsappBusinessAccount;
use App\Models\WhatsappPhoneNumber;
use App\Modules\WhatsApp\Exceptions\WhatsAppApiException;
use App\Modules\WhatsApp\Services\WhatsAppMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 5: the Meta integration seam. These tests force the REAL provider path
 * (use_fake=false) and stub the network with Http::fake, so we exercise payload
 * shaping, error mapping, retries and masked api_logs without touching Meta.
 */
class WhatsAppProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Route the factory to the real MetaWhatsAppProvider.
        config()->set('services.meta.use_fake', false);
        config()->set('services.meta.retry_delay_ms', 0);
    }

    private function phoneNumber(): WhatsappPhoneNumber
    {
        $tenant = Tenant::factory()->create();
        $waba = WhatsappBusinessAccount::factory()->create([
            'tenant_id' => $tenant->id,
            'access_token' => 'EAAG-super-secret-system-user-token-1234',
        ]);

        return WhatsappPhoneNumber::factory()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_business_account_id' => $waba->id,
            'phone_number_id' => '109999888877',
        ]);
    }

    public function test_send_text_returns_wamid_and_writes_masked_api_log(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.HBgLMTIzABCDEF']],
            ], 200),
        ]);

        $phone = $this->phoneNumber();
        $service = app(WhatsAppMessageService::class);

        $result = $service->sendText($phone, '919812345678', 'Hello there');

        $this->assertSame('wamid.HBgLMTIzABCDEF', $service->wamid($result));

        // Outbound call logged, scoped to the tenant.
        $log = ApiLog::where('tenant_id', $phone->tenant_id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('meta', $log->service);
        $this->assertSame(200, $log->status_code);

        // The recipient phone is masked to last-4 in the stored request body.
        $encoded = json_encode($log->request);
        $this->assertStringNotContainsString('919812345678', $encoded);
        $this->assertStringContainsString('5678', $encoded);
    }

    public function test_access_token_never_appears_in_logs(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);

        $phone = $this->phoneNumber();
        app(WhatsAppMessageService::class)->sendText($phone, '919812345678', 'hi');

        foreach (ApiLog::all() as $log) {
            $blob = json_encode([$log->request, $log->response]);
            $this->assertStringNotContainsString('super-secret-system-user-token', $blob);
        }
    }

    public function test_meta_error_is_mapped_to_friendly_exception(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['code' => 131026, 'message' => 'Message undeliverable', 'type' => 'OAuthException'],
            ], 400),
        ]);

        $phone = $this->phoneNumber();

        try {
            app(WhatsAppMessageService::class)->sendText($phone, '919812345678', 'hi');
            $this->fail('Expected WhatsAppApiException');
        } catch (WhatsAppApiException $e) {
            $this->assertSame(131026, $e->metaCode);
            $this->assertSame(422, $e->httpStatus);
            $this->assertStringContainsString('recipient may not be available', $e->userMessage);
            // Raw Meta detail retained for internal logs only.
            $this->assertSame('Message undeliverable', $e->getMessage());
        }

        // The failed call is still logged with its error code.
        $this->assertSame('131026', ApiLog::latest('id')->first()->error_code);
    }

    public function test_expired_token_maps_to_401(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'expired']], 401),
        ]);

        $phone = $this->phoneNumber();

        try {
            app(WhatsAppMessageService::class)->sendText($phone, '919812345678', 'hi');
            $this->fail('Expected WhatsAppApiException');
        } catch (WhatsAppApiException $e) {
            $this->assertSame(401, $e->httpStatus);
            $this->assertStringContainsString('reconnect', strtolower($e->userMessage));
        }
    }

    public function test_transient_5xx_is_retried_then_succeeds(): void
    {
        Http::fakeSequence('graph.facebook.com/*')
            ->push(['error' => ['code' => 131000, 'message' => 'temporary']], 500)
            ->push(['messages' => [['id' => 'wamid.RETRIED']]], 200);

        $phone = $this->phoneNumber();
        $result = app(WhatsAppMessageService::class)->sendText($phone, '919812345678', 'hi');

        $this->assertSame('wamid.RETRIED', $result['messages'][0]['id']);
    }
}
