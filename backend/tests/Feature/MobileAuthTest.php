<?php

namespace Tests\Feature;

use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mobile auth helpers: the "Continue on web" hand-off must issue a token that
 * still works (item 1 regression), be single-use, and native Google sign-in
 * must fail closed when the mobile client id is not configured (item 3).
 */
class MobileAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlanSeeder::class);
    }

    private function token(): string
    {
        return $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Owner', 'email' => 'owner@acme.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertCreated()->json('data.token');
    }

    public function test_web_session_handoff_issues_a_usable_single_use_token(): void
    {
        $token = $this->token();

        $res = $this->withToken($token)->postJson('/api/v1/auth/web-session', ['next' => '/billing']);
        $res->assertOk();
        $loginUrl = $res->json('data.login_url');
        $this->assertNotEmpty($loginUrl);
        $code = basename(parse_url($loginUrl, PHP_URL_PATH));

        // Opening the one-time link redirects to the web callback with a token + next.
        $redirect = $this->get('/api/v1/auth/mobile-login/' . $code);
        $redirect->assertRedirect();
        $location = $redirect->headers->get('Location');
        $this->assertStringContainsString('/auth/callback', $location);
        $this->assertStringContainsString('next=%2Fbilling', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
        $handoffToken = $q['token'] ?? '';
        $this->assertNotEmpty($handoffToken);

        // The handed-off token MUST still work (not blacklisted — item 1).
        $this->withToken($handoffToken)->getJson('/api/v1/auth/me')->assertOk();

        // Second use of the same code is rejected (single use).
        $this->get('/api/v1/auth/mobile-login/' . $code)
            ->assertRedirect()
            ->assertRedirectContains('error=link_expired');
    }

    public function test_native_google_fails_closed_when_mobile_client_id_is_unset(): void
    {
        config(['services.google.mobile_client_id' => null]);

        // A bad token would normally fail at Google; the config guard must trip
        // first with a 503 not_configured rather than ever skipping the aud check.
        $this->postJson('/api/v1/auth/google', ['id_token' => 'x'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'not_configured');
    }
}
