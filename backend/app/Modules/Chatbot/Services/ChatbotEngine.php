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
    ) {}

    public function handleInbound(
        WhatsappPhoneNumber $phone,
        Contact $contact,
        Conversation $conversation,
        string $type,
        ?string $body,
        ?Message $message = null
    ): void {
        if ($conversation->assigned_agent_id) {
            Log::info('chatbot: skipped — chat is assigned to an agent', ['conversation' => $conversation->id]);
            return;
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
            $this->replyText($phone, $to, $rule->response_text, $conversation, $contact);
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
