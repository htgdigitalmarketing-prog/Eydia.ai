<?php

namespace App\Modules\WhatsApp\Services;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageStatus;
use App\Models\WebhookEvent;
use App\Models\WebhookLog;
use App\Models\WhatsappPhoneNumber;
use App\Modules\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Modules\WhatsApp\Services\WhatsAppProviderFactory;
use App\Support\Services\BaseService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WhatsAppWebhookService extends BaseService
{
    public function handleInboundMessage(WebhookEvent $event): void
    {
        $payload = $event->payload;
        $phoneNumberId = $payload['metadata']['phone_number_id'] ?? null;
        $msg = $payload['message'] ?? [];
        $contactInfo = $payload['contacts'][0] ?? [];

        $phone = WhatsappPhoneNumber::with('businessAccount')->where('phone_number_id', $phoneNumberId)->first();

        if (! $phone) {
            $this->log($event, 'warning', 'message.orphaned', 'Phone number not found in system', [
                'phone_number_id' => $phoneNumberId,
            ]);
            return;
        }

        $tenantId = $phone->tenant_id;
        $event->update(['tenant_id' => $tenantId]);

        $waId = $contactInfo['wa_id'] ?? ($msg['from'] ?? null);
        if (! $waId) {
            $this->log($event, 'warning', 'message.no_sender', 'No wa_id in webhook payload');
            return;
        }

        $profileName = $contactInfo['profile']['name'] ?? null;

        $contact = Contact::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId, 'wa_id' => $waId],
            [
                'phone' => '+' . $waId,
                'name' => $profileName,
                'source' => 'inbound',
            ]
        );

        $isNewContact = $contact->wasRecentlyCreated;

        if ($profileName && ! $contact->name) {
            $contact->update(['name' => $profileName]);
        }
        $contact->update(['last_interaction_at' => now()]);

        $conversation = Conversation::withoutGlobalScopes()->firstOrCreate(
            [
                'contact_id' => $contact->id,
                'whatsapp_phone_number_id' => $phone->id,
            ],
            [
                'tenant_id' => $tenantId,
                'status' => 'open',
                'unread_count' => 0,
                'is_bot_active' => false,
            ]
        );

        $conversation->update([
            'window_expires_at' => now()->addHours(24),
            'status' => $conversation->status === 'closed' ? 'open' : $conversation->status,
        ]);

        $msgType = $msg['type'] ?? 'text';
        $body = $this->extractBody($msg, $msgType);
        $wamid = $msg['id'] ?? null;

        $existing = $wamid
            ? Message::withoutGlobalScopes()->where('external_message_id', $wamid)->first()
            : null;

        if ($existing) {
            $this->log($event, 'info', 'message.duplicate', "Duplicate wamid: {$wamid}");
            return;
        }

        $message = Message::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'whatsapp_phone_number_id' => $phone->id,
            'direction' => Message::DIRECTION_INBOUND,
            'type' => $msgType,
            'body' => $body,
            'external_message_id' => $wamid,
            'reply_to_external_id' => $msg['context']['id'] ?? null,
            'status' => 'received',
            'payload' => $msg,
        ]);

        if (in_array($msgType, ['image', 'video', 'audio', 'document', 'sticker'])) {
            $mediaData = $msg[$msgType] ?? [];
            $metaMediaId = $mediaData['id'] ?? null;

            $attachment = MessageAttachment::create([
                'tenant_id' => $tenantId,
                'message_id' => $message->id,
                'type' => $msgType,
                'mime_type' => $mediaData['mime_type'] ?? null,
                'meta_media_id' => $metaMediaId,
                'caption' => $mediaData['caption'] ?? null,
                'file_name' => $mediaData['filename'] ?? null,
            ]);

            if ($metaMediaId) {
                try {
                    $waba = $phone->businessAccount;
                    $provider = $waba
                        ? app(WhatsAppProviderFactory::class)->for($waba)
                        : app(WhatsAppProviderInterface::class);
                    $downloaded = $provider->downloadMedia($metaMediaId);

                    $ext = $this->guessExtension($downloaded['mime_type'] ?? $mediaData['mime_type'] ?? null, $msgType);
                    $storagePath = "whatsapp/media/{$tenantId}/{$attachment->uuid}.{$ext}";

                    Storage::disk('local')->put($storagePath, $downloaded['content']);

                    $attachment->update([
                        'storage_disk' => 'local',
                        'storage_path' => $storagePath,
                        'file_size' => $downloaded['file_size'] ?? strlen($downloaded['content']),
                        'mime_type' => $downloaded['mime_type'] ?? $attachment->mime_type,
                    ]);
                } catch (\Throwable $e) {
                    $this->log($event, 'warning', 'media.download_failed', "Failed to download media {$metaMediaId}: {$e->getMessage()}");
                }
            }
        }

        $conversation->update([
            'last_message_at' => now(),
            'last_message_preview' => Str::limit($body ?? "[$msgType]", 100),
            'unread_count' => $conversation->unread_count + 1,
            // The customer just replied — restart the follow-up clock.
            'last_customer_message_at' => now(),
            'followup_stage' => 0,
        ]);

        $this->log($event, 'info', 'message.received', "Inbound {$msgType} from {$waId}", [
            'message_id' => $message->id,
            'conversation_id' => $conversation->id,
        ]);

        // Chatbot auto-reply (best-effort — never blocks message intake).
        try {
            app(\App\Modules\Chatbot\Services\ChatbotEngine::class)
                ->handleInbound($phone, $contact, $conversation->fresh(), $msgType, $body, $message->fresh('attachments'));
        } catch (\Throwable $e) {
            $this->log($event, 'warning', 'chatbot.failed', 'Chatbot auto-reply error: ' . $e->getMessage());
        }

        // Mobile push notification (best-effort — never blocks message intake).
        try {
            app(\App\Modules\Mobile\Services\PushNotificationService::class)->notifyTenant(
                $tenantId,
                $contact->name ?: $waId,
                $body ?: "[{$msgType}]",
                ['type' => 'whatsapp_message', 'conversation_id' => $conversation->uuid],
            );
        } catch (\Throwable $e) {
            $this->log($event, 'warning', 'push.failed', 'Push notification error: ' . $e->getMessage());
        }

        // Hot-lead detection and automations each make their own (sometimes AI)
        // calls. They don't need to block the customer's reply — the chatbot reply
        // was already sent above — so run them AFTER the webhook responds. This
        // keeps the reply fast and the webhook well under Meta's retry timeout.
        $contactId = $contact->id;
        $conversationId = $conversation->id;
        $bodyText = (string) $body;
        $type = $msgType;
        $isNew = $isNewContact;

        app()->terminating(function () use ($tenantId, $contactId, $conversationId, $bodyText, $type, $isNew) {
            $contact = Contact::withoutGlobalScopes()->find($contactId);
            $conversation = Conversation::withoutGlobalScopes()->find($conversationId);
            if (! $contact || ! $conversation) {
                return;
            }

            try {
                app(\App\Modules\Chatbot\Services\HotLeadDetector::class)
                    ->evaluate($contact, $conversation, $type, $bodyText);
            } catch (\Throwable $e) {
                Log::warning('Hot-lead detection error: ' . $e->getMessage());
            }

            try {
                $dispatcher = app(\App\Modules\Automation\Services\WorkflowDispatcher::class);
                $dispatcher->resumeOnReply($contact, $conversation, $bodyText);
                if ($isNew) {
                    $dispatcher->fire($tenantId, 'new_contact', $contact, $conversation);
                }
                $dispatcher->fire($tenantId, 'incoming_message', $contact, $conversation, ['body' => $bodyText]);
                $dispatcher->fire($tenantId, 'keyword', $contact, $conversation, ['body' => $bodyText]);
            } catch (\Throwable $e) {
                Log::warning('Automation dispatch error: ' . $e->getMessage());
            }
        });
    }

    public function handleStatusUpdate(WebhookEvent $event): void
    {
        $payload = $event->payload;
        $statusData = $payload['status'] ?? [];

        $wamid = $statusData['id'] ?? null;
        $statusName = $statusData['status'] ?? null;
        $timestamp = isset($statusData['timestamp'])
            ? \Carbon\Carbon::createFromTimestamp($statusData['timestamp'])
            : now();

        if (! $wamid || ! $statusName) {
            $this->log($event, 'warning', 'status.incomplete', 'Missing wamid or status name');
            return;
        }

        $message = Message::withoutGlobalScopes()
            ->where('external_message_id', $wamid)
            ->first();

        if (! $message) {
            $this->log($event, 'info', 'status.no_message', "No message found for wamid: {$wamid}");
            return;
        }

        $event->update(['tenant_id' => $message->tenant_id]);

        $existingStatus = MessageStatus::withoutGlobalScopes()
            ->where('message_id', $message->id)
            ->where('status', $statusName)
            ->exists();

        if (! $existingStatus) {
            MessageStatus::withoutGlobalScopes()->create([
                'tenant_id' => $message->tenant_id,
                'message_id' => $message->id,
                'status' => $statusName,
                'occurred_at' => $timestamp,
                'conversation_id_meta' => $statusData['conversation']['id'] ?? null,
                'pricing_category' => $statusData['pricing']['category'] ?? null,
                'pricing_model' => $statusData['pricing']['pricing_model'] ?? null,
                'billable' => $statusData['pricing']['billable'] ?? false,
                'error_code' => $statusData['errors'][0]['code'] ?? null,
                'error_message' => $statusData['errors'][0]['title'] ?? null,
            ]);
        }

        $statusOrder = ['queued' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4, 'failed' => 0];
        $currentOrder = $statusOrder[$message->status] ?? 0;
        $newOrder = $statusOrder[$statusName] ?? 0;

        if ($newOrder > $currentOrder || $statusName === 'failed') {
            $updates = ['status' => $statusName];

            match ($statusName) {
                'sent' => $updates['sent_at'] = $timestamp,
                'delivered' => $updates['delivered_at'] = $timestamp,
                'read' => $updates['read_at'] = $timestamp,
                'failed' => $updates = array_merge($updates, [
                    'failed_at' => $timestamp,
                    'error_code' => $statusData['errors'][0]['code'] ?? null,
                    'error_message' => $statusData['errors'][0]['title'] ?? null,
                ]),
                default => null,
            };

            $message->update($updates);
        }

        $this->log($event, 'info', 'status.updated', "Status {$statusName} for wamid: {$wamid}");
    }

    private function guessExtension(?string $mime, string $fallbackType): string
    {
        $map = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
            'video/mp4' => 'mp4', 'video/3gpp' => '3gp',
            'audio/aac' => 'aac', 'audio/mp4' => 'm4a', 'audio/mpeg' => 'mp3', 'audio/amr' => 'amr',
            'audio/ogg' => 'ogg', 'audio/ogg; codecs=opus' => 'ogg',
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'text/plain' => 'txt',
            'image/webp' => 'webp',
        ];

        if ($mime && isset($map[$mime])) return $map[$mime];

        return match ($fallbackType) {
            'image' => 'jpg', 'video' => 'mp4', 'audio' => 'ogg', 'document' => 'bin', 'sticker' => 'webp',
            default => 'bin',
        };
    }

    private function extractBody(array $msg, string $type): ?string
    {
        return match ($type) {
            'text' => $msg['text']['body'] ?? null,
            'image', 'video', 'document', 'sticker' => $msg[$type]['caption'] ?? null,
            'location' => sprintf('Location: %s, %s', $msg['location']['latitude'] ?? '', $msg['location']['longitude'] ?? ''),
            'contacts' => 'Contact card',
            'reaction' => $msg['reaction']['emoji'] ?? null,
            'button' => $msg['button']['text'] ?? null,
            'interactive' => $msg['interactive']['button_reply']['title']
                ?? $msg['interactive']['list_reply']['title']
                ?? null,
            default => null,
        };
    }

    private function log(WebhookEvent $event, string $level, string $action, string $message, array $context = []): void
    {
        WebhookLog::create([
            'tenant_id' => $event->tenant_id,
            'webhook_event_id' => $event->id,
            'level' => $level,
            'action' => $action,
            'message' => $message,
            'context' => $context,
        ]);
    }
}
