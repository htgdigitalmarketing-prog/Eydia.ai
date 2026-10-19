<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsappPhoneNumber;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleanupColdBroadcastsTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId;
    private WhatsappPhoneNumber $phone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlanSeeder::class);

        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Owner', 'email' => 'owner@acme.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ]);
        $this->tenantId = User::withoutGlobalScopes()->where('email', 'owner@acme.test')->value('tenant_id');

        Subscription::create([
            'tenant_id' => $this->tenantId, 'plan_id' => Plan::where('slug', 'enterprise')->value('id'),
            'status' => 'active', 'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        ]);

        $this->phone = WhatsappPhoneNumber::create([
            'tenant_id' => $this->tenantId, 'whatsapp_business_account_id' => \App\Models\WhatsappBusinessAccount::create([
                'tenant_id' => $this->tenantId, 'waba_id' => '100', 'name' => 'Biz',
            ])->id,
            'phone_number_id' => '200', 'display_phone_number' => '+91 90000 00000', 'is_default' => true,
        ]);
    }

    private function conversation(string $wa): Conversation
    {
        $contact = Contact::create([
            'tenant_id' => $this->tenantId, 'wa_id' => $wa, 'phone' => "+{$wa}", 'source' => 'bulk',
        ]);

        return Conversation::create([
            'tenant_id' => $this->tenantId, 'contact_id' => $contact->id,
            'whatsapp_phone_number_id' => $this->phone->id, 'status' => 'open',
        ]);
    }

    private function message(Conversation $conv, string $direction, string $status): void
    {
        Message::create([
            'tenant_id' => $this->tenantId, 'conversation_id' => $conv->id, 'contact_id' => $conv->contact_id,
            'whatsapp_phone_number_id' => $this->phone->id, 'direction' => $direction, 'type' => 'template',
            'body' => 'Festive offer', 'status' => $status,
        ]);
    }

    public function test_it_removes_cold_chats_but_keeps_engaged_ones(): void
    {
        // Cold: outbound-only, failed delivery, no reply.
        $cold = $this->conversation('919000000001');
        $this->message($cold, Message::DIRECTION_OUTBOUND, 'failed');

        // Engaged: the customer replied.
        $engaged = $this->conversation('919000000002');
        $this->message($engaged, Message::DIRECTION_OUTBOUND, 'failed');
        $this->message($engaged, Message::DIRECTION_INBOUND, 'received');

        // Dry run deletes nothing.
        $this->artisan('whatsapp:cleanup-broadcasts', ['--tenant' => $this->tenantId])->assertSuccessful();
        $this->assertSame(2, Conversation::withoutGlobalScopes()->whereNull('deleted_at')->count());

        // Apply removes only the cold one.
        $this->artisan('whatsapp:cleanup-broadcasts', ['--tenant' => $this->tenantId, '--apply' => true, '--force' => true])->assertSuccessful();

        $this->assertNotNull($cold->fresh()->deleted_at, 'cold chat should be soft-deleted');
        $this->assertNull($engaged->fresh()->deleted_at, 'engaged chat should remain');

        // The failed message in the cold chat no longer counts (soft-deleted).
        $this->assertSame(0, Message::where('conversation_id', $cold->id)->where('status', 'failed')->count());
        $this->assertSame(1, Message::where('conversation_id', $engaged->id)->where('status', 'failed')->count());

        // Contacts are kept.
        $this->assertSame(2, Contact::withoutGlobalScopes()->count());
    }

    public function test_restore_brings_back_everything_the_cleanup_removed(): void
    {
        $cold = $this->conversation('919000000001');
        $this->message($cold, Message::DIRECTION_OUTBOUND, 'failed');

        $this->artisan('whatsapp:cleanup-broadcasts', ['--tenant' => $this->tenantId, '--apply' => true, '--force' => true])->assertSuccessful();
        $this->assertNotNull($cold->fresh()->deleted_at);

        // Restore (dry-run then apply).
        $this->artisan('whatsapp:restore-broadcasts', ['--tenant' => $this->tenantId])->assertSuccessful();
        $this->assertNotNull($cold->fresh()->deleted_at, 'dry-run must not restore');

        $this->artisan('whatsapp:restore-broadcasts', ['--tenant' => $this->tenantId, '--apply' => true])->assertSuccessful();

        $this->assertNull($cold->fresh()->deleted_at, 'chat should be back');
        $this->assertSame(1, Message::where('conversation_id', $cold->id)->count(), 'its message should be back');
    }
}
