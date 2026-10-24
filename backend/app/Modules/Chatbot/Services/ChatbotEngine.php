<?php

namespace App\Modules\Chatbot\Services;

use App\Models\Chatbot;
use App\Models\ChatbotRule;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsappPhoneNumber;
use App\Modules\WhatsApp\Services\WhatsAppMessageService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Auto-reply engine. On an inbound text message, finds the active chatbot for
 * the receiving number, matches the text against its keyword rules (by priority,
 * first match wins), and sends the configured response — a text or a template.
 * If nothing matches and a fallback message is set, sends that.
 */
class ChatbotEngine
{
    public function __construct(
        private readonly WhatsAppMessageService $messages,
        private readonly AiReplyService $ai,
        private readonly LeadFlowAutomation $leadFlow,
    ) {}

    public function handleInbound(
        WhatsappPhoneNumber $phone,
        Contact $contact,
        Conversation $conversation,
        string $type,
        ?string $body,
        ?Message $message = null
    ): void {
        // Any inbound message is a fresh reply, so the pending lead-flow nudge/
        // drip for this chat is dropped — the clock resets (marketing doc §3).
        try {
            $this->leadFlow->onCustomerReply($conversation);
        } catch (\Throwable $e) {
            Log::warning('chatbot: lead-flow reply reset failed: ' . $e->getMessage());
        }

        if ($conversation->assigned_agent_id) {
            Log::info('chatbot: skipped — chat is assigned to an agent', ['conversation' => $conversation->id]);
            return;
        }

        // A tapped quick-reply/list button arrives as 'interactive' or 'button';
        // the button's title is already in $body, so treat it like plain text and
        // let the keyword rules match it (powers the button-driven lead funnel).
        if (in_array($type, ['interactive', 'button'], true)) {
            $type = 'text';
        }

        // Handle plain text, and images/PDFs (which the AI can read). Other media
        // types (audio, video, stickers, location) are ignored.
        $isMedia = in_array($type, ['image', 'document'], true);
        if ($type !== 'text' && ! $isMedia) {
            return;
        }
        if ($type === 'text' && ! $body) {
            return;
        }

        $chatbot = Chatbot::withoutGlobalScopes()
            ->where('tenant_id', $phone->tenant_id)
            ->where('is_active', true)
            ->where(function ($q) use ($phone) {
                $q->whereNull('whatsapp_phone_number_id')
                  ->orWhere('whatsapp_phone_number_id', $phone->id);
            })
            ->orderByRaw('whatsapp_phone_number_id IS NULL') // number-specific bot wins over a general one
            ->first();

        if (! $chatbot) {
            Log::info('chatbot: no active chatbot for this number', ['tenant' => $phone->tenant_id, 'phone' => $phone->id]);
            return;
        }

        $to = $contact->wa_id ?: ltrim((string) $contact->phone, '+');
        if (! $to) {
            return;
        }

        try {
            if ($isMedia) {
                $this->handleMedia($phone, $to, $chatbot, $conversation, $contact, $message, $body);
                return;
            }

            $rules = ChatbotRule::withoutGlobalScopes()
                ->where('chatbot_id', $chatbot->id)
                ->where('is_active', true)
                ->orderBy('priority')
                ->get();

            $matched = $rules->first(fn (ChatbotRule $rule) => $this->matches($rule, $body));

            if ($matched) {
                Log::info('chatbot: rule matched', ['chatbot' => $chatbot->id, 'keyword' => $matched->keyword]);
                $this->sendResponse($phone, $to, $matched, $conversation, $contact);
                $this->applyLeadActions($phone, $contact, $matched);
                $this->leadFlow->scheduleForBranch($phone, $conversation, $contact, $chatbot, $matched);
            } elseif (! empty($chatbot->welcome_buttons) && $chatbot->welcome_message) {
                // Button-driven flow: re-show the welcome menu for a first message
                // or any unrecognized input (covers greetings and spam gracefully).
                $result = $this->messages->sendButtons($phone, $to, $chatbot->welcome_message, (array) $chatbot->welcome_buttons);
                $this->recordOutbound($phone, $conversation, $contact, $chatbot->welcome_message, 'interactive', $result);
            } elseif ($chatbot->ai_enabled) {
                $aiReply = $this->ai->generate($chatbot, $conversation, $body);
                if ($aiReply) {
                    Log::info('chatbot: AI replied', ['chatbot' => $chatbot->id]);
                    $this->replyText($phone, $to, $aiReply, $conversation, $contact);
                } elseif ($chatbot->fallback_message) {
                    Log::warning('chatbot: AI enabled but returned nothing (check AI API key/model) — sending fallback', ['chatbot' => $chatbot->id]);
                    $this->replyText($phone, $to, $chatbot->fallback_message, $conversation, $contact);
                } else {
                    Log::warning('chatbot: AI enabled but returned nothing and no fallback set (check GEMINI_API_KEY/ANTHROPIC_API_KEY on the server)', ['chatbot' => $chatbot->id]);
                }
            } elseif ($chatbot->fallback_message) {
                $this->replyText($phone, $to, $chatbot->fallback_message, $conversation, $contact);
            } else {
                Log::info('chatbot: no rule matched, AI off, no fallback — no reply sent', ['chatbot' => $chatbot->id]);
            }
        } catch (\Throwable $e) {
            Log::warning('Chatbot auto-reply failed: ' . $e->getMessage(), ['chatbot' => $chatbot->id]);
        }
    }

    /** Let the AI look at an inbound image/PDF and reply about it. */
    private function handleMedia(
        WhatsappPhoneNumber $phone,
        string $to,
        Chatbot $chatbot,
        Conversation $conversation,
        Contact $contact,
        ?Message $message,
        ?string $caption
    ): void {
        if (! $chatbot->ai_enabled) {
            if ($chatbot->fallback_message) {
                $this->replyText($phone, $to, $chatbot->fallback_message, $conversation, $contact);
            }
            return;
        }

        $media = $message ? $this->readMedia($message) : null;
        $aiReply = $media
            ? $this->ai->describeMedia($chatbot, $caption, $media['bytes'], $media['mime'])
            : null;

        if ($aiReply) {
            Log::info('chatbot: AI replied to media', ['chatbot' => $chatbot->id]);
            $this->replyText($phone, $to, $aiReply, $conversation, $contact);
        } elseif ($chatbot->fallback_message) {
            Log::warning('chatbot: AI could not read media — sending fallback', ['chatbot' => $chatbot->id]);
            $this->replyText($phone, $to, $chatbot->fallback_message, $conversation, $contact);
        } else {
            Log::warning('chatbot: AI could not read media and no fallback set', ['chatbot' => $chatbot->id]);
        }
    }

    /**
     * Read a stored inbound attachment's bytes + mime, for images and PDFs only
     * and under a 5 MB cap. Returns null when there is nothing usable.
     *
     * @return array{bytes:string, mime:string}|null
     */
    private function readMedia(Message $message): ?array
    {
        $att = $message->attachments()->first();
        if (! $att || ! $att->storage_path) {
            return null;
        }

        $mime = (string) $att->mime_type;
        if (! str_starts_with($mime, 'image/') && $mime !== 'application/pdf') {
            return null;
        }

        try {
            $disk = Storage::disk($att->storage_disk ?: 'local');
            if (! $disk->exists($att->storage_path) || $disk->size($att->storage_path) > 5 * 1024 * 1024) {
                return null;
            }

            return ['bytes' => $disk->get($att->storage_path), 'mime' => $mime];
        } catch (\Throwable $e) {
            Log::warning('chatbot: failed to read media attachment: ' . $e->getMessage());
            return null;
        }
    }

    private function matches(ChatbotRule $rule, string $text): bool
    {
        $t = mb_strtolower(trim($text));
        $k = mb_strtolower(trim((string) $rule->keyword));
        if ($k === '') {
            return false;
        }

        return match ($rule->match_type) {
            'exact' => $t === $k,
            'starts_with' => str_starts_with($t, $k),
            'regex' => @preg_match('/' . $rule->keyword . '/iu', $text) === 1,
            default => str_contains($t, $k), // contains
        };
    }

    private function sendResponse(WhatsappPhoneNumber $phone, string $to, ChatbotRule $rule, Conversation $conversation, Contact $contact): void
    {
        if ($rule->response_type === 'template' && $rule->template_name) {
            $result = $this->messages->sendTemplate($phone, $to, $rule->template_name, 'en');
            $body = \App\Models\Template::bodyText($phone->tenant_id, $rule->template_name) ?: "📄 Template: {$rule->template_name}";
            $this->recordOutbound($phone, $conversation, $contact, $body, 'template', $result);
        } elseif ($rule->response_text) {
            $buttons = is_array($rule->buttons) ? array_filter($rule->buttons) : [];
            if ($buttons !== []) {
                $result = $this->messages->sendButtons($phone, $to, $rule->response_text, $buttons);
                $this->recordOutbound($phone, $conversation, $contact, $rule->response_text, 'interactive', $result);
            } else {
                $this->replyText($phone, $to, $rule->response_text, $conversation, $contact);
            }
        }
    }

    /**
     * CRM side-effects of a matched funnel rule: upsert a WhatsApp Lead for the
     * contact, set its pipeline stage and funnel tag, and notify the team when
     * the lead asks to talk to a human.
     */
    private function applyLeadActions(WhatsappPhoneNumber $phone, Contact $contact, ChatbotRule $rule): void
    {
        if (! $rule->set_tag && ! $rule->set_stage && ! $rule->notify_agent) {
            return;
        }

        $number = $contact->phone ?: ($contact->wa_id ? '+' . ltrim((string) $contact->wa_id, '+') : null);

        if ($number) {
            try {
                $lead = \App\Models\Lead::withoutGlobalScopes()->firstOrNew([
                    'tenant_id' => $phone->tenant_id,
                    'phone' => $number,
                ]);
                if (! $lead->exists) {
                    $lead->source = 'WHATSAPP';
                    $lead->name = $contact->name ?: $number;
                    $lead->stage = 'NEW';
                }
                if ($rule->set_stage) {
                    $lead->stage = $rule->set_stage;
                }
                if ($rule->set_tag) {
                    $meta = $lead->meta ?? [];
                    $meta['tag'] = $rule->set_tag;
                    $meta['flow'] = 'gbp_lead_automation';
                    $lead->meta = $meta;
                }
                $lead->last_contacted_at = now();
                $lead->save();
            } catch (\Throwable $e) {
                Log::warning('chatbot: lead tagging failed: ' . $e->getMessage());
            }
        }

        if ($rule->notify_agent) {
            try {
                app(\App\Modules\Mobile\Services\PushNotificationService::class)->notifyTenant(
                    $phone->tenant_id,
                    'Lead wants to talk to a human',
                    ($contact->name ?: $number ?: 'A lead') . ' asked to talk to your team on WhatsApp.',
                    ['type' => 'lead_handoff'],
                );
            } catch (\Throwable $e) {
                Log::warning('chatbot: handoff notify failed: ' . $e->getMessage());
            }
        }
    }

    private function replyText(WhatsappPhoneNumber $phone, string $to, string $body, Conversation $conversation, Contact $contact): void
    {
        $result = $this->messages->sendText($phone, $to, $body);
        $this->recordOutbound($phone, $conversation, $contact, $body, 'text', $result);
    }

    /** Log the bot's reply as an outbound message so it shows in the inbox. */
    private function recordOutbound(WhatsappPhoneNumber $phone, Conversation $conversation, Contact $contact, string $body, string $type, array $result): void
    {
        $wamid = $this->messages->wamid($result);

        Message::withoutGlobalScopes()->create([
            'tenant_id' => $phone->tenant_id,
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'whatsapp_phone_number_id' => $phone->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'type' => $type,
            'body' => $body,
            'external_message_id' => $wamid,
            'status' => 'sent',
        ]);

        $conversation->update([
            'last_message_at' => now(),
            'last_message_preview' => Str::limit($body, 100),
        ]);
    }
}
