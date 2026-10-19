<?php

namespace App\Modules\Chatbot\Services;

use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Message;
use App\Modules\WhatsApp\Services\WhatsAppMessageService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends up to two automatic follow-up nudges when a customer goes quiet: one at
 * +6h and one at +12h from their last message, both inside WhatsApp's 24h
 * free-form window. Any reply from the customer resets the clock (handled in the
 * webhook), so a nudge is only sent while they are still silent.
 *
 * Config lives on the active chatbot (followup_enabled + followup_message).
 */
class FollowUpService
{
    private const FIRST_AFTER_HOURS = 6;
    private const SECOND_AFTER_HOURS = 12;

    private const DEFAULT_MESSAGE = 'Hi! Aap aage process karna chahte hain? '
        . 'Agar koi sawaal ho to bataiye, hum aapki madad ke liye yahin hain.';

    public function __construct(private readonly WhatsAppMessageService $messages) {}

    /** Send every follow-up that is due right now. Runs in the scheduler (all tenants). */
    public function sendDue(): int
    {
        $sent = 0;

        $candidates = Conversation::withoutGlobalScopes()
            ->where('status', 'open')
            ->whereNull('assigned_agent_id')
            ->where('followup_stage', '<', 2)
            ->whereNotNull('last_customer_message_at')
            // Only within the 24h window — a free-form text would be rejected after that.
            ->where('last_customer_message_at', '>', now()->subHours(24))
            ->with(['contact', 'phoneNumber'])
            ->limit(200)
            ->get();

        foreach ($candidates as $conversation) {
            try {
                if ($this->sendIfDue($conversation)) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                Log::warning('followup: failed for conversation ' . $conversation->id . ': ' . $e->getMessage());
            }
        }

        return $sent;
    }

    private function sendIfDue(Conversation $conversation): bool
    {
        $last = $conversation->last_customer_message_at;
        $stage = (int) $conversation->followup_stage;

        $dueHours = $stage === 0 ? self::FIRST_AFTER_HOURS : self::SECOND_AFTER_HOURS;
        if ($last->copy()->addHours($dueHours)->isFuture()) {
            return false; // not time yet
        }

        $phone = $conversation->phoneNumber;
        $contact = $conversation->contact;
        if (! $phone || ! $contact) {
            return false;
        }

        $chatbot = Chatbot::withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('is_active', true)
            ->where('followup_enabled', true)
            ->where(function ($q) use ($phone) {
                $q->whereNull('whatsapp_phone_number_id')->orWhere('whatsapp_phone_number_id', $phone->id);
            })
            ->orderByRaw('whatsapp_phone_number_id IS NULL')
            ->first();

        if (! $chatbot) {
            return false; // follow-ups not enabled for this number
        }

        $to = $contact->wa_id ?: ltrim((string) $contact->phone, '+');
        if (! $to) {
            return false;
        }

        $text = trim((string) $chatbot->followup_message) ?: self::DEFAULT_MESSAGE;
        $result = $this->messages->sendText($phone, $to, $text);

        Message::withoutGlobalScopes()->create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'whatsapp_phone_number_id' => $phone->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'type' => 'text',
            'body' => $text,
            'external_message_id' => $this->messages->wamid($result),
            'status' => 'sent',
        ]);

        // Advance the stage WITHOUT touching last_customer_message_at, so the
        // second nudge is still measured from the customer's last message.
        $conversation->forceFill([
            'followup_stage' => $stage + 1,
            'last_message_at' => now(),
            'last_message_preview' => Str::limit($text, 100),
        ])->saveQuietly();

        return true;
    }
}
