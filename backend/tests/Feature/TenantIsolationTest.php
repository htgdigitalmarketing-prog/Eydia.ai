<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The non-negotiable multi-tenancy guarantee (ARCHITECTURE §J):
 * a user in Tenant A can never read or write a Tenant B row, and tenant_id is
 * always derived from the authenticated user — never trusted from input.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_scope_hides_other_tenants_rows(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $userA = User::factory()->forTenant($tenantA)->create();

        Contact::factory()->count(3)->create(['tenant_id' => $tenantA->id]);
        Contact::factory()->count(5)->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($userA);

        // Scoped read: only Tenant A's contacts are visible.
        $this->assertSame(3, Contact::count());
        $this->assertTrue(Contact::query()->get()->every(fn ($c) => $c->tenant_id === $tenantA->id));
    }

    public function test_tenant_id_is_derived_from_auth_not_input(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = User::factory()->forTenant($tenantA)->create();

        $this->actingAs($userA);

        // Attempt to smuggle another tenant's id via mass assignment — must be ignored.
        $contact = Contact::create([
            'tenant_id' => $tenantB->id,
            'wa_id' => '919900000000',
            'phone' => '+919900000000',
            'name' => 'Injected',
        ]);

        $this->assertSame($tenantA->id, $contact->fresh()->tenant_id);
    }

    public function test_a_tenant_cannot_fetch_another_tenants_contact_by_id(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = User::factory()->forTenant($tenantA)->create();

        $foreign = Contact::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($userA);

        $this->assertNull(Contact::find($foreign->id));
    }
}
