<?php

namespace Tests\Feature;

use App\Models\LoginOtp;
use App\Models\User;
use App\Models\WhatsappBusinessAccount;
use App\Models\WhatsappPhoneNumber;
use App\Modules\WhatsApp\Services\WhatsAppMessageService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OtpLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // A registered owner with a phone number.
        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Owner', 'email' => 'owner@acme.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ]);
        User::withoutGlobalScopes()->where('email', 'owner@acme.test')
            ->update(['phone' => '+91 98765 43210']);
    }

    public function test_otp_login_succeeds_with_the_right_code(): void
    {
        // Request a code — in local/testing the code is returned for the test.
        $code = $this->postJson('/api/v1/auth/otp/request', ['phone' => '9876543210'])
            ->assertOk()
            ->json('data.dev_code');
        $this->assertNotNull($code);

        // Verify it and get a token.
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '+919876543210', 'code' => $code])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'owner@acme.test')
            ->assertJsonStructure(['data' => ['token', 'token_type', 'expires_in']]);
    }

    public function test_wrong_code_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '9876543210'])->assertOk();

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9876543210', 'code' => '000000'])
            ->assertStatus(422);
    }

    public function test_expired_code_is_rejected(): void
    {
        $code = $this->postJson('/api/v1/auth/otp/request', ['phone' => '9876543210'])->json('data.dev_code');

        LoginOtp::query()->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9876543210', 'code' => $code])
            ->assertStatus(422);
    }

    public function test_otp_is_delivered_via_whatsapp_when_a_template_is_configured(): void
    {
        config(['services.otp.whatsapp_template' => 'login_otp', 'services.otp.whatsapp_lang' => 'en']);

        $tenantId = User::withoutGlobalScopes()->where('email', 'owner@acme.test')->value('tenant_id');
        $waba = WhatsappBusinessAccount::create(['tenant_id' => $tenantId, 'waba_id' => '100', 'name' => 'Biz']);
        WhatsappPhoneNumber::create([
            'tenant_id' => $tenantId, 'whatsapp_business_account_id' => $waba->id,
            'phone_number_id' => '200', 'display_phone_number' => '+91 90000 00000', 'is_default' => true,
        ]);

        // Expect the OTP to be sent as the configured template, with the code as
        // the body parameter, to the user's own number.
        $mock = $this->mock(WhatsAppMessageService::class);
        $mock->shouldReceive('sendTemplate')
            ->once()
            ->withArgs(function ($phone, $to, $template, $lang, $components) {
                $code = $components[0]['parameters'][0]['text'] ?? '';
                // Authentication template: code on both the body and the button.
                return $template === 'login_otp'
                    && $to === '919876543210'
                    && preg_match('/^\d{6}$/', $code) === 1
                    && ($components[1]['type'] ?? '') === 'button'
                    && ($components[1]['parameters'][0]['text'] ?? '') === $code;
            })
            ->andReturn(['messages' => [['id' => 'wamid.OTP']]]);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '919876543210'])->assertOk();
    }

    public function test_unknown_number_cannot_log_in_even_with_the_right_code(): void
    {
        // The request looks successful (no enumeration). In local/testing the code
        // is echoed for convenience, but it belongs to no account...
        $code = $this->postJson('/api/v1/auth/otp/request', ['phone' => '9000000000'])
            ->assertOk()->json('data.dev_code');

        // ...so even the correct code can't log anyone in.
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9000000000', 'code' => $code])
            ->assertStatus(422);
    }
}
