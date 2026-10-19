<?php

namespace Tests\Feature;

use App\Models\LeadStatus;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlanSeeder::class);
    }

    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Acme Realty',
            'name' => 'Owner One',
            'email' => 'owner@acme.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ], $overrides);
    }

    public function test_register_provisions_tenant_owner_role_and_wallet_without_a_plan(): void
    {
        $res = $this->postJson('/api/v1/auth/register', $this->registerPayload());

        $res->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'owner@acme.test')
            ->assertJsonPath('data.token_type', 'bearer')
            ->assertJsonStructure(['data' => ['token', 'expires_in', 'user' => ['id', 'roles', 'tenant' => ['id']]]]);

        $this->assertSame(1, Tenant::count());
        $tenant = Tenant::first();

        $user = User::withoutGlobalScopes()->where('email', 'owner@acme.test')->first();
        $this->assertSame($tenant->id, $user->tenant_id);
        $this->assertTrue($user->hasRole('tenant-owner'));

        $this->assertSame(1, Wallet::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(5, LeadStatus::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_register_rejects_duplicate_email(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();

        $this->postJson('/api/v1/auth/register', $this->registerPayload(['company_name' => 'Other']))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors('email');
    }

    public function test_login_returns_token_and_me_returns_current_user(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@acme.test',
            'password' => 'Password123',
        ])->assertOk();

        $token = $login->json('data.token');
        $this->assertNotEmpty($token);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'owner@acme.test')
            ->assertJsonPath('data.user.roles.0', 'tenant-owner');
    }

    public function test_login_fails_with_bad_credentials(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@acme.test',
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_protected_route_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401)->assertJsonPath('success', false);
        $this->getJson('/api/v1/ping')->assertStatus(401)->assertJsonPath('success', false);
    }

    public function test_authenticated_owner_reaches_tenant_route_with_own_tenant(): void
    {
        $token = $this->postJson('/api/v1/auth/register', $this->registerPayload())->json('data.token');
        $tenant = Tenant::first();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/ping')
            ->assertOk()
            ->assertJsonPath('data.pong', true)
            ->assertJsonPath('data.tenant_id', $tenant->id);
    }

    public function test_suspended_tenant_is_blocked_by_middleware(): void
    {
        $token = $this->postJson('/api/v1/auth/register', $this->registerPayload())->json('data.token');

        Tenant::query()->update(['status' => 'suspended', 'suspended_at' => now()]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/ping')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_forgot_password_sends_reset_notification(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'owner@acme.test'])->assertOk();

        $user = User::withoutGlobalScopes()->where('email', 'owner@acme.test')->first();
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_updates_credentials(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();
        $user = User::withoutGlobalScopes()->where('email', 'owner@acme.test')->first();
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'owner@acme.test',
            'token' => $token,
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@acme.test',
            'password' => 'NewPassword123',
        ])->assertOk();
    }
}
