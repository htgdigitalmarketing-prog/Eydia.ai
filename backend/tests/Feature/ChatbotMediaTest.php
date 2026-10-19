<?php

namespace Tests\Feature;

use App\Models\Chatbot;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Tenant;
use App\Models\WhatsappPhoneNumber;
use App\Modules\Chatbot\Services\AiReplyService;
use App\Modules\Chatbot\Services\ChatbotEngine;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ChatbotMediaTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId;
    private WhatsappPhoneNumber $phone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');

        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);
        $this->tenantId = $tenant->id;
        $this->phone = WhatsappPhoneNumber::factory()->create(['tenant_id' => $tenant->id]);
    }

    private function chatbot(bool $ai, ?string $fallback): Chatbot
    {
        return Chatbot::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Support bot',
            'is_active' => true,
            'ai_enabled' => $ai,
            'ai_instructions' => 'We sell electrical goods.',
            'fallback_message' => $fallback,
        ]);
    }

    /** Create an inbound image message with a stored attachment. */
    private function inboundImage(string $mime = 'image/jpeg', ?string $caption = null): array
    {
        $contact = Contact::create([
            'tenant_id' => $this->tenantId, 'wa_id' => '919999999999',
            'phone' => '+919999999999', 'name' => 'Atul', 'source' => 'inbound',
        ]);
        $conversation = Conversation::create([
            'tenant_id' => $this->tenantId, 'contact_id' => $contact->id,
            'whatsapp_phone_number_id' => $this->phone->id, 'status' => 'open',
        ]);
        $message = Message::create([
            'tenant_id' => $this->tenantId, 'conversation_id' => $conversation->id,
            'contact_id' => $contact->id, 'whatsapp_phone_number_id' => $this->phone->id,
            'direction' => Message::DIRECTION_INBOUND, 'type' => 'image', 'body' => $caption,
            'status' => 'received',
        ]);
        $path = "whatsapp/media/{$this->tenantId}/test.jpg";
        Storage::disk('local')->put($path, 'fake-image-bytes');
        MessageAttachment::create([
            'tenant_id' => $this->tenantId, 'message_id' => $message->id, 'type' => 'image',
            'mime_type' => $mime, 'storage_disk' => 'local', 'storage_path' => $path, 'caption' => $caption,
        ]);

        return [$contact, $conversation, $message->fresh('attachments')];
    }

    private function deliver(Chatbot $chatbot, $contact, $conversation, $message): void
    {
        app(ChatbotEngine::class)->handleInbound(
            $this->phone, $contact, $conversation, 'image', $message->body, $message
        );
    }

    public function test_ai_reads_an_inbound_image_and_replies(): void
    {
        $this->mock(AiReplyService::class, function (Mockery\MockInterface $m) {
            $m->shouldReceive('describeMedia')
                ->once()
                ->with(Mockery::type(Chatbot::class), null, 'fake-image-bytes', 'image/jpeg')
                ->andReturn('Yeh ek bijli ka bill lag raha hai, total Rs 1757.');
        });

        [$contact, $conversation, $message] = $this->inboundImage();
        $this->deliver($this->chatbot(true, 'Please wait for an agent.'), $contact, $conversation, $message);

        $reply = Message::withoutGlobalScopes()
            ->where('direction', Message::DIRECTION_OUTBOUND)->latest('id')->first();
        $this->assertNotNull($reply);
        $this->assertSame('Yeh ek bijli ka bill lag raha hai, total Rs 1757.', $reply->body);
    }

    public function test_falls_back_when_ai_cannot_read_the_image(): void
    {
        $this->mock(AiReplyService::class, function (Mockery\MockInterface $m) {
            $m->shouldReceive('describeMedia')->once()->andReturn(null);
        });

        [$contact, $conversation, $message] = $this->inboundImage();
        $this->deliver($this->chatbot(true, 'Please wait for an agent.'), $contact, $conversation, $message);

        $reply = Message::withoutGlobalScopes()
            ->where('direction', Message::DIRECTION_OUTBOUND)->latest('id')->first();
        $this->assertSame('Please wait for an agent.', $reply->body);
    }

    public function test_media_is_ignored_when_ai_is_off_and_no_fallback(): void
    {
        [$contact, $conversation, $message] = $this->inboundImage();
        $this->deliver($this->chatbot(false, null), $contact, $conversation, $message);

        $this->assertSame(0, Message::withoutGlobalScopes()
            ->where('direction', Message::DIRECTION_OUTBOUND)->count());
    }
}
