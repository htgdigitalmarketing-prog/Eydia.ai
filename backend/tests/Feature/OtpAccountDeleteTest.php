<?php

namespace Tests\Feature;

use App\Models\LoginOtp;
use App\Models\User;
use App\Modules\Auth\Services\OtpService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OTP-registered users (random password, no google_id) must be able to delete
 * their own account by confirming an OTP — the Google Play "delete account" rule.
 */
class OtpAccountDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function otpUser(): array
    {
        // Register a phone-only user via the OTP flow (dev_code is returned in tests).
        $code = app(OtpService::class)->request('919999900001', true)['dev_code'];
        $res = $this->postJson('/api/v1/auth/otp/verify', ['phone' => '919999900001', 'code' => $code, 'register' => true]);
        $res->assertOk();

        return [$res->json('data.token'), User::where('phone', '+919999900001')->firstOrFail()];
    }

    public function test_otp_user_can_delete_account_with_a_code(): void
    {
        [$token, $user] = $this->otpUser();

        // Request a fresh delete code, then confirm the deletion with it.
        LoginOtp::query()->delete(); // clear the register code so cooldown doesn't block
        $code = app(OtpService::class)->request('919999900001')['dev_code'];

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/auth/account', ['code' => $code])
            ->assertOk();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_wrong_code_is_rejected(): void
    {
        [$token] = $this->otpUser();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/auth/account', ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'otp_invalid');
    }

    public function test_company_name_can_be_renamed_via_profile(): void
    {
        [$token, $user] = $this->otpUser();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/auth/profile', ['company_name' => 'Acme Dental'])
            ->assertOk();

        $this->assertSame('Acme Dental', $user->fresh()->tenant->company_name);
    }
}
