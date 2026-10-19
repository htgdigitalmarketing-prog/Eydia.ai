<?php

namespace Tests\Feature;

use App\Models\BulkSend;
use App\Models\BulkSendRecipient;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Template;
use App\Models\TemplateComponent;
use App\Models\User;
use App\Models\WhatsappBusinessAccount;
use App\Models\WhatsappPhoneNumber;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BulkSendTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlanSeeder::class);

        // The inbox mirror fetches header images over HTTP and stores them.
        Storage::fake('local');
        Http::fake(['*' => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg'])]);

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

        $this->authed()->postJson('/api/v1/whatsapp/connect-manual', [
            'waba_id' => '100000000000001',
            'phone_number_id' => '200000000000001',
            'display_phone_number' => '+91 98765 43210',
            'access_token' => 'EAAG-demo-system-user-token-abcdefghij',
        ])->assertCreated();
    }

    private function authed(): static
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    /** A send left "processing" by a worker that died $ageMinutes ago. */
    private function stalledSend(int $ageMinutes): BulkSend
    {
        $phone = WhatsappPhoneNumber::withoutGlobalScopes()->firstOrFail();
        $send = BulkSend::create([
            'tenant_id' => $phone->tenant_id,
            'whatsapp_phone_number_id' => $phone->id,
            'template_name' => 'hello_world',
            'language' => 'en_US',
            'status' => 'processing',
            'total' => 1,
        ]);
        BulkSendRecipient::create(['bulk_send_id' => $send->id, 'phone' => '919999999999']);

        BulkSend::withoutGlobalScopes()->whereKey($send->id)->update([
            'created_at' => now()->subMinutes($ageMinutes),
            'updated_at' => now()->subMinutes($ageMinutes),
        ]);

        return $send;
    }

    public function test_single_number_send_completes_within_the_request(): void
    {
        $this->authed()->postJson('/api/v1/whatsapp/bulk-send', [
            'numbers' => ['+91 98765 43210'],
            'template' => 'hello_world',
            'language' => 'en_US',
        ])
            ->assertCreated()
            ->assertJsonPath('data.bulk_send.status', 'completed')
            ->assertJsonPath('data.bulk_send.sent_count', 1)
            ->assertJsonPath('data.bulk_send.failed_count', 0)
            ->assertJsonPath('data.bulk_send.recipients.0.status', 'sent');
    }

    public function test_stalled_send_is_resumed_when_polled(): void
    {
        $send = $this->stalledSend(5);

        $this->authed()->getJson("/api/v1/whatsapp/bulk-sends/{$send->uuid}")->assertOk();

        $send->refresh();
        $this->assertSame('completed', $send->status);
        $this->assertSame(1, $send->sent_count);
        $this->assertSame('sent', BulkSendRecipient::where('bulk_send_id', $send->id)->value('status'));
    }

    public function test_scheduler_resumes_stalled_sends(): void
    {
        $send = $this->stalledSend(5);

        $this->artisan('whatsapp:resume-sends')->assertSuccessful();

        $this->assertSame('completed', $send->fresh()->status);
    }

    public function test_send_older_than_a_day_is_closed_out_instead_of_sent(): void
    {
        $send = $this->stalledSend(60 * 48);

        $this->authed()->getJson("/api/v1/whatsapp/bulk-sends/{$send->uuid}")
            ->assertOk()
            ->assertJsonPath('data.bulk_send.status', 'failed')
            ->assertJsonPath('data.bulk_send.recipients.0.status', 'failed');
    }

    public function test_recently_active_send_is_not_resumed_twice(): void
    {
        $send = $this->stalledSend(0);

        $this->authed()->getJson("/api/v1/whatsapp/bulk-sends/{$send->uuid}")->assertOk();

        $this->assertSame('processing', $send->fresh()->status);
        $this->assertSame('pending', BulkSendRecipient::where('bulk_send_id', $send->id)->value('status'));
    }

    /** Create an APPROVED template whose HEADER is an image. */
    private function imageHeaderTemplate(string $name, string $lang): void
    {
        $waba = WhatsappBusinessAccount::withoutGlobalScopes()->firstOrFail();
        $tpl = Template::create([
            'tenant_id' => $waba->tenant_id,
            'whatsapp_business_account_id' => $waba->id,
            'name' => $name, 'language' => $lang, 'category' => 'MARKETING', 'status' => 'APPROVED',
        ]);
        TemplateComponent::create([
            'tenant_id' => $waba->tenant_id, 'template_id' => $tpl->id,
            'type' => 'HEADER', 'format' => 'IMAGE', 'sort_order' => 0,
        ]);
    }

    public function test_image_header_template_without_a_url_is_rejected(): void
    {
        $this->imageHeaderTemplate('promo_img', 'en_US');

        $this->authed()->postJson('/api/v1/whatsapp/bulk-send', [
            'numbers' => ['+91 98765 43210'],
            'template' => 'promo_img',
            'language' => 'en_US',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('header_media_url');

        $this->assertSame(0, BulkSend::withoutGlobalScopes()->count());
    }

    public function test_image_header_template_sends_with_a_url(): void
    {
        $this->imageHeaderTemplate('promo_img', 'en_US');

        $this->authed()->postJson('/api/v1/whatsapp/bulk-send', [
            'numbers' => ['+91 98765 43210'],
            'template' => 'promo_img',
            'language' => 'en_US',
            'header_media_url' => 'https://example.com/banner.jpg',
        ])
            ->assertCreated()
            ->assertJsonPath('data.bulk_send.status', 'completed')
            ->assertJsonPath('data.bulk_send.sent_count', 1);

        // The image header component is built and persisted for the send.
        $send = BulkSend::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame('header', $send->components[0]['type']);
        $this->assertSame('image', $send->components[0]['parameters'][0]['type']);
        $this->assertSame('https://example.com/banner.jpg', $send->components[0]['parameters'][0]['image']['link']);
    }

    public function test_upload_header_returns_a_media_id(): void
    {
        $this->authed()->postJson('/api/v1/whatsapp/bulk-send/upload-header', [
            'file' => UploadedFile::fake()->image('banner.jpg', 600, 400),
        ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['media_id']]);
    }

    public function test_image_header_template_sends_with_an_uploaded_media_id(): void
    {
        $this->imageHeaderTemplate('promo_img', 'en_US');

        $this->authed()->postJson('/api/v1/whatsapp/bulk-send', [
            'numbers' => ['+91 98765 43210'],
            'template' => 'promo_img',
            'language' => 'en_US',
            'header_media_id' => 'media_abc123',
        ])->assertCreated()->assertJsonPath('data.bulk_send.status', 'completed');

        $send = BulkSend::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame('media_abc123', $send->components[0]['parameters'][0]['image']['id']);
    }

    public function test_bulk_send_mirrors_into_an_existing_chat_with_its_image(): void
    {
        $this->imageHeaderTemplate('promo_img', 'en_US');
        $phone = WhatsappPhoneNumber::withoutGlobalScopes()->firstOrFail();

        // An existing contact + conversation for the recipient.
        $contact = \App\Models\Contact::create([
            'tenant_id' => $phone->tenant_id, 'wa_id' => '919876543210',
            'phone' => '+919876543210', 'name' => 'Known', 'source' => 'inbound',
        ]);
        $conv = \App\Models\Conversation::create([
            'tenant_id' => $phone->tenant_id, 'contact_id' => $contact->id,
            'whatsapp_phone_number_id' => $phone->id, 'status' => 'open',
        ]);

        $this->authed()->postJson('/api/v1/whatsapp/bulk-send', [
            'numbers' => ['919876543210'],
            'template' => 'promo_img',
            'language' => 'en_US',
            'header_media_url' => 'https://example.com/banner.jpg',
        ])->assertCreated();

        $msg = \App\Models\Message::withoutGlobalScopes()
            ->where('conversation_id', $conv->id)
            ->where('direction', \App\Models\Message::DIRECTION_OUTBOUND)->latest('id')->first();
        $this->assertNotNull($msg);
        $this->assertSame('image', $msg->type);
        $this->assertSame(1, \App\Models\MessageAttachment::withoutGlobalScopes()
            ->where('message_id', $msg->id)->where('type', 'image')->count());
    }

    public function test_bulk_send_does_not_create_a_chat_for_a_cold_number(): void
    {
        $this->imageHeaderTemplate('promo_img', 'en_US');

        $this->authed()->postJson('/api/v1/whatsapp/bulk-send', [
            'numbers' => ['919000000099'],
            'template' => 'promo_img',
            'language' => 'en_US',
            'header_media_url' => 'https://example.com/banner.jpg',
        ])->assertCreated();

        $this->assertSame(0, \App\Models\Conversation::withoutGlobalScopes()
            ->whereHas('contact', fn ($q) => $q->where('wa_id', '919000000099'))->count());
    }

    public function test_retry_resends_only_the_failed_numbers(): void
    {
        $phone = WhatsappPhoneNumber::withoutGlobalScopes()->firstOrFail();
        $send = BulkSend::create([
            'tenant_id' => $phone->tenant_id, 'whatsapp_phone_number_id' => $phone->id,
            'template_name' => 'hello_world', 'language' => 'en_US',
            'status' => 'failed', 'total' => 2, 'sent_count' => 1, 'failed_count' => 1,
        ]);
        BulkSendRecipient::create(['bulk_send_id' => $send->id, 'phone' => '919000000001', 'status' => 'sent']);
        BulkSendRecipient::create(['bulk_send_id' => $send->id, 'phone' => '919000000002', 'status' => 'failed', 'error_message' => 'boom']);

        $this->authed()->postJson("/api/v1/whatsapp/bulk-sends/{$send->uuid}/retry")
            ->assertOk()
            ->assertJsonPath('data.bulk_send.status', 'completed')
            ->assertJsonPath('data.bulk_send.sent_count', 2)
            ->assertJsonPath('data.bulk_send.failed_count', 0);

        $this->assertSame('sent', BulkSendRecipient::where('bulk_send_id', $send->id)
            ->where('phone', '919000000002')->value('status'));
    }
}
