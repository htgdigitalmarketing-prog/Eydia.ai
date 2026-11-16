<?php

namespace Tests\Feature;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Freemium gating for Google Business Profile: a tenant with NO plan can connect
 * and view, but every paid action (reply, post, edit, automation…) is blocked.
 * Regression guard — feature:dash_gbp alone does NOT block no-plan tenants
 * (feature() treats "no plan" as allowed), so the paid routes also need 'plan'.
 */
class GbpGatingTest extends TestCase
{
    use RefreshDatabase;

    private function freeOwner(): string
    {
        return $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Owner', 'email' => 'owner@acme.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->json('data.token');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_no_plan_cannot_reply_to_a_review(): void
    {
        $token = $this->freeOwner();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/gbp/reviews/some-uuid/reply', ['reply' => 'Thank you!'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'plan_required');
    }

    public function test_no_plan_cannot_create_a_post_or_edit_profile(): void
    {
        $token = $this->freeOwner();
        $h = $this->withHeader('Authorization', "Bearer {$token}");

        $h->postJson('/api/v1/gbp/posts', [])->assertStatus(403)->assertJsonPath('code', 'plan_required');
        $h->putJson('/api/v1/gbp/profile', [])->assertStatus(403)->assertJsonPath('code', 'plan_required');
        $h->putJson('/api/v1/gbp/automation', [])->assertStatus(403)->assertJsonPath('code', 'plan_required');
    }

    public function test_no_plan_can_reach_free_connect_and_view(): void
    {
        $token = $this->freeOwner();
        $h = $this->withHeader('Authorization', "Bearer {$token}");

        // These are free (not plan-gated) — they must NOT return plan_required.
        foreach (['/api/v1/gbp/connection', '/api/v1/gbp/reviews', '/api/v1/gbp/overview'] as $url) {
            $res = $h->getJson($url);
            $this->assertNotSame('plan_required', $res->json('code'), "{$url} should not be plan-gated");
        }
    }
}
