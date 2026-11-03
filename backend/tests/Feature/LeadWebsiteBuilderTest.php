<?php

namespace Tests\Feature;

use App\Models\BusinessSite;
use App\Models\User;
use App\Modules\Gbp\Services\BusinessSiteGenerator;
use App\Modules\Gbp\Services\BusinessSiteRenderer;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The WhatsApp website-builder's lead site: a microsite built from just a
 * business name (no Google listing), renders as a clean About/Services page.
 * AI is unavailable in tests, so this exercises the graceful-fallback content.
 */
class LeadWebsiteBuilderTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlanSeeder::class);

        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Owner', 'email' => 'owner@acme.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertCreated();
        $this->tenantId = (int) User::withoutGlobalScopes()->where('email', 'owner@acme.test')->value('tenant_id');
    }

    public function test_generate_for_lead_creates_a_location_less_site(): void
    {
        $gen = app(BusinessSiteGenerator::class);
        $site = $gen->generateForLead($this->tenantId, 'Sharma Sweets', 'a sweet shop in Noida');

        $this->assertInstanceOf(BusinessSite::class, $site);
        $this->assertNull($site->gbp_location_id);
        $this->assertSame('Sharma Sweets', $site->title);
        $this->assertNotEmpty($site->slug);
        $this->assertTrue((bool) ($site->data['is_lead'] ?? false));
        $this->assertNotEmpty($site->data['about']);
        $this->assertStringContainsString('/site/', BusinessSiteGenerator::url($site));
    }

    public function test_lead_site_renders_about_section_not_health_report(): void
    {
        $gen = app(BusinessSiteGenerator::class);
        $site = $gen->generateForLead($this->tenantId, 'Sharma Sweets');

        $html = app(BusinessSiteRenderer::class)->render($site->data);

        $this->assertStringContainsString('Sharma Sweets', $html);
        $this->assertStringContainsString('About us', $html);
        $this->assertStringNotContainsString('Your Business Health Report', $html);
    }
}
