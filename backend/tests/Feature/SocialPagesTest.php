<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SocialPagesTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlanSeeder::class);

        $this->token = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Owner', 'email' => 'owner@acme.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->json('data.token');

        Subscription::create([
            'tenant_id' => User::withoutGlobalScopes()->where('email', 'owner@acme.test')->value('tenant_id'),
            'plan_id' => Plan::where('slug', 'enterprise')->value('id'),
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);
    }

    private function authed(): static
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    public function test_connection_exposes_the_facebook_app_id_for_login(): void
    {
        config(['services.meta.app_id' => '123456789']);

        $this->authed()->getJson('/api/v1/social/connection')
            ->assertOk()
            ->assertJsonPath('data.facebook_app_id', '123456789');
    }

    public function test_pages_lists_the_users_facebook_pages_from_a_token(): void
    {
        config(['services.meta.app_id' => '123', 'services.meta.app_secret' => 'secret']);

        Http::fake([
            '*oauth/access_token*' => Http::response(['access_token' => 'LONG_LIVED'], 200),
            '*me/accounts*' => Http::response(['data' => [
                ['id' => '111', 'name' => 'Alluring Decor', 'access_token' => 'PAGE_TOKEN_1',
                 'instagram_business_account' => ['id' => '999', 'username' => 'alluring.decor']],
                ['id' => '222', 'name' => 'Second Page', 'access_token' => 'PAGE_TOKEN_2'],
            ]], 200),
        ]);

        $this->authed()->postJson('/api/v1/social/pages', ['user_token' => 'USER_TOKEN'])
            ->assertOk()
            ->assertJsonPath('data.pages.0.name', 'Alluring Decor')
            ->assertJsonPath('data.pages.0.ig_username', 'alluring.decor')
            ->assertJsonPath('data.pages.1.name', 'Second Page')
            ->assertJsonPath('data.pages.1.ig_username', null);
    }

    public function test_pages_requires_a_user_token(): void
    {
        $this->authed()->postJson('/api/v1/social/pages', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_token');
    }
}
