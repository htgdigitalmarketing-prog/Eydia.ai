<?php

namespace App\Modules\WhatsApp\Services;

use App\Models\BlacklistEntry;
use App\Models\BulkSend;
use App\Models\BulkSendRecipient;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Template;
use App\Models\WhatsappPhoneNumber;
use App\Modules\WhatsApp\Exceptions\WhatsAppApiException;
use App\Support\Services\BaseService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sends one template to many numbers without a queue worker (shared hosting).
 *
 * The first few seconds of sending happen inside the request, so small sends
 * finish before the response. The rest continues in an app()->terminating()
 * callback after the response is flushed — a plain closure, never serialized.
 * If the host kills that process, a stalled send (no heartbeat for
 * STALE_SECONDS) is resumed by the next status poll or the scheduler.
 */
class BulkSendService extends BaseService
{
    public const STALE_SECONDS = 150;

    private const INLINE_SECONDS = 8;

    /** Older stalled sends are closed out instead of messaging people days late. */
    private const MAX_RESUME_AGE_HOURS = 24;

    public function __construct(private readonly WhatsAppMessageService $messages) {}

    public function send(int $tenantId, int $userId, array $numbers, string $template, string $language, array $components = []): BulkSend
    {
        // Prefer the default active number; fall back to ANY active number.
        $phone = WhatsappPhoneNumber::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['connected', 'registered'])
            ->orderByDesc('is_default')
            ->first();

        if (! $phone) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'template' => ['No active WhatsApp number found. Please connect and register a WhatsApp number on the WhatsApp page before sending.'],
            ]);
        }

        $numbers = collect($numbers)
            ->map(fn ($n) => preg_replace('/\D/', '', $n))
            ->filter(fn ($n) => strlen($n) >= 10 && strlen($n) <= 15)
            ->unique()
            ->values()
            ->all();

        // Drop black-listed numbers so opted-out users are never messaged.
        $blocked = BlacklistEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->pluck('phone')
            ->all();
        if (! empty($blocked)) {
            $numbers = array_values(array_diff($numbers, $blocked));
        }

        if (empty($numbers)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'numbers' => ['No valid phone numbers left to send (all were invalid or black-listed).'],
            ]);
        }

        $bulkSend = BulkSend::create([
            'tenant_id' => $tenantId,
            'whatsapp_phone_number_id' => $phone->id,
            'template_name' => $template,
            'language' => $language,
            'components' => $components ?: null,
            'status' => 'processing',
            'total' => count($numbers),
            'created_by' => $userId,
        ]);

        $now = now();
        BulkSendRecipient::insert(array_map(fn ($number) => [
            'bulk_send_id' => $bulkSend->id,
            'phone' => $number,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ], $numbers));

        $this->process($bulkSend, self::INLINE_SECONDS);

        if ($bulkSend->status === 'processing') {
            $this->continueAfterResponse($bulkSend);
        }

        return $bulkSend->fresh(['phoneNumber', 'recipients']);
    }

    /**
     * Re-send only the failed recipients of a bulk send, reusing the saved
     * template/components — so the user never re-enters anything.
     */
    public function retryFailed(BulkSend $bulkSend): BulkSend
    {
        $failed = BulkSendRecipient::where('bulk_send_id', $bulkSend->id)->where('status', 'failed')->count();
        if ($failed === 0) {
            return $bulkSend->fresh(['phoneNumber', 'recipients']);
        }

        BulkSendRecipient::where('bulk_send_id', $bulkSend->id)
            ->where('status', 'failed')
            ->update([
                'status' => 'pending', 'error_message' => null, 'error_code' => null,
                'wamid' => null, 'sent_at' => null, 'updated_at' => now(),
            ]);

        $bulkSend->forceFill(['status' => 'processing', 'updated_at' => now()])->save();

        $this->process($bulkSend, self::INLINE_SECONDS);
        if ($bulkSend->status === 'processing') {
            $this->continueAfterResponse($bulkSend);
        }

        return $bulkSend->fresh(['phoneNumber', 'recipients']);
    }

    /** The template body with {{n}} filled from the send's variables, for inbox display. */
    private function renderBody(BulkSend $bulkSend): string
    {
        $text = Template::bodyText($bulkSend->tenant_id, $bulkSend->template_name, $bulkSend->language)
            ?: ('📄 ' . $bulkSend->template_name);

        $bodyComp = collect($bulkSend->components ?? [])->firstWhere('type', 'body');
        foreach (($bodyComp['parameters'] ?? []) as $i => $p) {
            $text = str_replace('{{' . ($i + 1) . '}}', (string) ($p['text'] ?? ''), $text);
        }

        return $text;
    }

    /**
     * Fetch the template's header image ONCE (from the public URL or the uploaded
     * media id) and store it locally, so each mirrored message can show it.
     *
     * @return array{path:string, mime:string}|null
     */
    private function resolveHeaderImage(BulkSend $bulkSend, WhatsappPhoneNumber $phone): ?array
    {
        $header = collect($bulkSend->components ?? [])->firstWhere('type', 'header');
        $param = $header['parameters'][0] ?? null;
        if (! $param || ($param['type'] ?? null) !== 'image') {
            return null; // only image headers are shown in the inbox
        }

        $media = $param['image'] ?? [];
        $path = "whatsapp/media/{$bulkSend->tenant_id}/bulk_{$bulkSend->id}.jpg";

        if (Storage::disk('local')->exists($path)) {
            return ['path' => $path, 'mime' => 'image/jpeg'];
        }

        try {
            if (! empty($media['link'])) {
                $res = Http::timeout(30)->get($media['link']);
                if ($res->failed()) {
                    return null;
                }
                $bytes = $res->body();
                $mime = $res->header('Content-Type') ?: 'image/jpeg';
            } elseif (! empty($media['id'])) {
                $dl = $this->messages->downloadMedia($phone, $media['id']);
                $bytes = $dl['content'];
                $mime = $dl['mime_type'] ?? 'image/jpeg';
            } else {
                return null;
            }

            Storage::disk('local')->put($path, $bytes);

            return ['path' => $path, 'mime' => $mime];
        } catch (\Throwable $e) {
            Log::warning('bulk: header image fetch failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Record a sent template as an outbound message in the recipient's chat —
     * but ONLY for contacts the business already has a conversation with. Cold
     * bulk numbers do not get a new inbox chat (which would flood the inbox and
     * pollute delivery analytics with unreachable numbers); their result stays
     * tracked on the bulk-send recipient row.
     */
    private function mirrorToInbox(BulkSend $bulkSend, WhatsappPhoneNumber $phone, string $number, string $bodyText, ?array $headerImage, ?string $wamid): void
    {
        try {
            $contact = Contact::withoutGlobalScopes()
                ->where('tenant_id', $bulkSend->tenant_id)
                ->where('wa_id', $number)
                ->first();
            if (! $contact) {
                return;
            }

            $conversation = Conversation::withoutGlobalScopes()
                ->where('contact_id', $contact->id)
                ->where('whatsapp_phone_number_id', $phone->id)
                ->first();
            if (! $conversation) {
                return; // only mirror into chats that already exist
            }

            $message = Message::withoutGlobalScopes()->create([
                'tenant_id' => $bulkSend->tenant_id,
                'conversation_id' => $conversation->id,
                'contact_id' => $contact->id,
                'whatsapp_phone_number_id' => $phone->id,
                'direction' => Message::DIRECTION_OUTBOUND,
                'type' => $headerImage ? 'image' : 'template',
                'body' => $bodyText,
                'external_message_id' => $wamid,
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            if ($headerImage) {
                MessageAttachment::withoutGlobalScopes()->create([
                    'tenant_id' => $bulkSend->tenant_id,
                    'message_id' => $message->id,
                    'type' => 'image',
                    'mime_type' => $headerImage['mime'],
                    'storage_disk' => 'local',
                    'storage_path' => $headerImage['path'],
                    'caption' => $bodyText,
                ]);
            }

            $conversation->forceFill([
                'last_message_at' => now(),
                'last_message_preview' => Str::limit($bodyText ?: '[template]', 100),
            ])->saveQuietly();
        } catch (\Throwable $e) {
            Log::warning('bulk: inbox mirror failed for ' . $number . ': ' . $e->getMessage());
        }
    }

    /** Send pending recipients until done, or until $maxSeconds elapses. */
    public function process(BulkSend $bulkSend, ?int $maxSeconds = null): void
    {
        $deadline = $maxSeconds ? microtime(true) + $maxSeconds : null;

        $phone = WhatsappPhoneNumber::withoutGlobalScopes()->find($bulkSend->whatsapp_phone_number_id);
        if (! $phone) {
            $this->failPending($bulkSend, 'The WhatsApp number used for this send is no longer connected.');
            return;
        }

        // Resolve the display content ONCE so each sent message can be mirrored
        // into the recipient's inbox chat (like it appears on their WhatsApp).
        $bodyText = $this->renderBody($bulkSend);
        $headerImage = $this->resolveHeaderImage($bulkSend, $phone);

        while ($deadline === null || microtime(true) < $deadline) {
            $recipient = BulkSendRecipient::where('bulk_send_id', $bulkSend->id)
                ->where('status', 'pending')
                ->orderBy('id')
                ->first();

            if (! $recipient) {
                break;
            }

            try {
                $result = $this->messages->sendTemplate(
                    $phone,
                    $recipient->phone,
                    $bulkSend->template_name,
                    $bulkSend->language,
                    $bulkSend->components ?? [],
                );
                $wamid = $this->messages->wamid($result);
                $recipient->update([
                    'status' => 'sent',
                    'wamid' => $wamid,
                    'sent_at' => now(),
                ]);
                $this->mirrorToInbox($bulkSend, $phone, $recipient->phone, $bodyText, $headerImage, $wamid);
            } catch (\Throwable $e) {
                $recipient->update([
                    'status' => 'failed',
                    'error_message' => Str::limit($e instanceof WhatsAppApiException ? $e->userMessage : $e->getMessage(), 500),
                ]);
            }

            $this->syncProgress($bulkSend);
            usleep(50000);
        }

        $this->syncProgress($bulkSend);
    }

    public function continueAfterResponse(BulkSend $bulkSend): void
    {
        $id = $bulkSend->id;

        app()->terminating(function () use ($id) {
            @set_time_limit(0);
            ignore_user_abort(true);

            $bulkSend = BulkSend::withoutGlobalScopes()->find($id);
            if ($bulkSend && $bulkSend->status === 'processing') {
                $this->process($bulkSend);
            }
        });
    }

    /**
     * Take ownership of a send whose worker stopped. Returns true when the caller
     * should now process it. The compare-and-swap on updated_at guarantees only
     * one poller wins, so recipients are never double-sent.
     */
    public function claimIfStalled(BulkSend $bulkSend): bool
    {
        if ($bulkSend->status !== 'processing' || $bulkSend->updated_at > now()->subSeconds(self::STALE_SECONDS)) {
            return false;
        }

        if ($bulkSend->created_at < now()->subHours(self::MAX_RESUME_AGE_HOURS)) {
            $this->failPending($bulkSend, 'Sending was interrupted and not retried because it is older than 24 hours.');
            return false;
        }

        return BulkSend::withoutGlobalScopes()
            ->whereKey($bulkSend->id)
            ->where('status', 'processing')
            ->where('updated_at', $bulkSend->updated_at)
            ->update(['updated_at' => now()]) === 1;
    }

    private function failPending(BulkSend $bulkSend, string $reason): void
    {
        BulkSendRecipient::where('bulk_send_id', $bulkSend->id)
            ->where('status', 'pending')
            ->update(['status' => 'failed', 'error_message' => $reason, 'updated_at' => now()]);

        $this->syncProgress($bulkSend);
    }

    /** Recount from recipients, finish the send when nothing is pending, and bump the heartbeat. */
    private function syncProgress(BulkSend $bulkSend): void
    {
        $counts = BulkSendRecipient::where('bulk_send_id', $bulkSend->id)
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")
            ->first();

        $total = (int) $counts->total;
        $pending = (int) $counts->pending;
        $failed = (int) $counts->failed;

        $bulkSend->sent_count = $total - $pending - $failed;
        $bulkSend->failed_count = $failed;
        if ($pending === 0) {
            $bulkSend->status = $total > 0 && $failed === $total ? 'failed' : 'completed';
        }
        $bulkSend->updated_at = now();
        $bulkSend->save();
    }
}
