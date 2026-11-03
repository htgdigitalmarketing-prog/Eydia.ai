<?php

namespace App\Modules\Chatbot\Services;

use App\Models\Chatbot;
use App\Models\Contact;
use App\Models\Conversation;
use App\Modules\Gbp\Services\BusinessSiteGenerator;
use Illuminate\Support\Facades\Log;

/**
 * The WhatsApp "free instant website" flow. When a chatbot has website_builder
 * on, this drives the chat: it asks the visitor for their business name, then
 * uses AI to analyse the business, builds a shareable microsite and replies with
 * the live link. State is kept per-conversation in meta['wb'].
 */
class WebsiteBuilderBot
{
    public function __construct(
        private readonly AiReplyService $ai,
        private readonly BusinessSiteGenerator $sites,
    ) {
    }

    /**
     * Handle one inbound message. Returns the reply to send, or null to let the
     * normal chatbot/AI handle it (e.g. once the site is already built).
     */
    public function handle(Chatbot $chatbot, Conversation $conversation, Contact $contact, string $body): ?string
    {
        $wb = (array) ($conversation->meta['wb'] ?? []);
        $stage = $wb['stage'] ?? null;

        // Site already built for this chat — hand back to normal conversation,
        // but if they clearly ask for the link again, resend it.
        if ($stage === 'done') {
            if (! empty($wb['url']) && $this->asksForLink($body)) {
                return "Here's your website again 👇\n{$wb['url']}";
            }

            return null;
        }

        $extract = $this->extract($body);

        // No business name yet → greet and ask for it (once), then wait.
        if (! $extract) {
            if ($stage !== 'awaiting') {
                $this->setState($conversation, ['stage' => 'awaiting', 'attempts' => 0]);

                return "Hi! 👋 I can build you a *free* business website in seconds — live and ready to share.\n\n"
                    . 'To start, what is your *business name* and what do you do? (e.g. "Sharma Sweets — a sweet shop in Noida")';
            }

            // Still no name after asking; nudge a couple of times, then give up
            // gracefully so we never loop forever.
            $attempts = (int) ($wb['attempts'] ?? 0) + 1;
            $this->setState($conversation, ['stage' => 'awaiting', 'attempts' => $attempts]);
            if ($attempts >= 3) {
                $this->setState($conversation, ['stage' => 'done']); // stop the flow
                return null;
            }

            return 'No problem 🙂 Just tell me your *business name* (and a few words about what you do) and I\'ll build your website.';
        }

        // Got a business name — build the site and send the link.
        try {
            $site = $this->sites->generateForLead($chatbot->tenant_id, $extract['name'], $extract['info'] ?? '');
            $url = BusinessSiteGenerator::url($site);
            $this->setState($conversation, ['stage' => 'done', 'url' => $url, 'site_slug' => $site->slug]);

            return "🎉 Done! Here's your brand-new website for *{$extract['name']}*:\n\n{$url}\n\n"
                . 'Open it, share it anywhere, and reply here if you\'d like us to add your logo, photos, services or contact details. 🚀';
        } catch (\Throwable $e) {
            Log::warning('website-builder: generate failed: ' . $e->getMessage());

            return 'Sorry, I hit a snag building your website just now. Please try again in a moment, or send your business name once more. 🙏';
        }
    }

    /**
     * Ask the AI whether the message names a business to build a site for.
     *
     * @return array{name:string, info:string}|null
     */
    private function extract(string $body): ?array
    {
        $body = trim($body);
        if ($body === '') {
            return null;
        }

        $system = 'You extract a business name from a WhatsApp message. Return ONLY JSON.';
        $user = "Message: \"{$body}\". Does it give a business NAME the person wants a website for? "
            . 'Return {"has":true,"name":"the business name","info":"what the business does, if mentioned, else empty"} '
            . 'or {"has":false} if there is no clear business name (a greeting like "hi" has none).';

        $raw = $this->ai->classify($system, [['role' => 'user', 'content' => $user]], 300);
        if (! $raw) {
            return null;
        }
        $s = strpos($raw, '{');
        $e = strrpos($raw, '}');
        if ($s === false || $e === false || $e < $s) {
            return null;
        }
        $parsed = json_decode(substr($raw, $s, $e - $s + 1), true);
        if (! is_array($parsed) || empty($parsed['has']) || empty($parsed['name'])) {
            return null;
        }

        return [
            'name' => trim((string) $parsed['name']),
            'info' => trim((string) ($parsed['info'] ?? '')),
        ];
    }

    private function asksForLink(string $body): bool
    {
        $b = mb_strtolower($body);

        foreach (['link', 'website', 'url', 'site', 'again', 'send'] as $kw) {
            if (str_contains($b, $kw)) {
                return true;
            }
        }

        return false;
    }

    private function setState(Conversation $conversation, array $wb): void
    {
        $meta = $conversation->meta ?? [];
        $meta['wb'] = $wb;
        $conversation->forceFill(['meta' => $meta])->save();
    }
}
