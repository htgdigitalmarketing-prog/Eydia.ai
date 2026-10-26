<?php

namespace App\Modules\Chatbot\Services;

use App\Models\AiSetting;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Generates an AI reply for a chatbot using the business's own instructions as
 * context. Supports two providers over plain HTTP (same pattern as the app's
 * other integrations): Google Gemini and Anthropic Claude. Returns null when AI
 * is unavailable/misconfigured so the caller can fall back gracefully.
 */
class AiReplyService
{
    private const ANTHROPIC_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const OPENAI_CHAT_ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    private const OPENAI_IMAGE_ENDPOINT = 'https://api.openai.com/v1/images/generations';
    // Fewer history turns = smaller prompt = faster first token (lower reply latency).
    private const HISTORY_LIMIT = 6;

    /** Lazily-loaded platform AI settings (admin's provider/key/model choice). */
    private ?AiSetting $aiSettings = null;

    private function settings(): AiSetting
    {
        if ($this->aiSettings === null) {
            try {
                $this->aiSettings = AiSetting::current();
            } catch (\Throwable) {
                $this->aiSettings = new AiSetting(); // table missing / early boot — env only
            }
        }

        return $this->aiSettings;
    }

    /** Effective API key for a provider (DB override, else .env). */
    private function keyFor(string $provider): ?string
    {
        return $this->settings()->keyFor($provider);
    }

    /** Effective model for a provider (DB override, else .env, else default). */
    private function modelFor(string $provider): string
    {
        return $this->settings()->modelFor($provider);
    }

    /** Dispatch a text completion to the chosen provider. */
    private function dispatchText(string $provider, string $system, array $messages): ?string
    {
        return match ($provider) {
            'anthropic' => $this->callAnthropic($system, $messages),
            'openai' => $this->callOpenAI($system, $messages),
            default => $this->callGemini($system, $messages),
        };
    }

    /** Dispatch a vision (image/PDF) question to the chosen provider. */
    private function dispatchVision(string $provider, string $system, string $question, string $bytes, string $mime): ?string
    {
        return match ($provider) {
            'anthropic' => $this->callAnthropicVision($system, $question, $bytes, $mime),
            'openai' => $this->callOpenAIVision($system, $question, $bytes, $mime),
            default => $this->callGeminiVision($system, $question, $bytes, $mime),
        };
    }

    public function generate(Chatbot $chatbot, Conversation $conversation, string $incoming): ?string
    {
        $chain = $this->providerChain();
        if ($chain === []) {
            Log::warning('AI reply skipped: no API key configured (set GEMINI_API_KEY or ANTHROPIC_API_KEY in .env, then php artisan config:clear)');
            return null; // no AI key configured — skip AI, let caller use fallback
        }

        $system = $this->systemPrompt($chatbot);
        $messages = $this->history($conversation, $incoming);

        // Try each configured provider in order; if one fails (e.g. an invalid or
        // expired key), fall through to the next instead of giving up silently.
        foreach ($chain as $provider) {
            try {
                $reply = $this->dispatchText($provider, $system, $messages);
                if ($reply) {
                    Log::info('AI reply: answered via ' . $provider);
                    return $reply;
                }
                Log::warning("AI reply: {$provider} returned nothing, trying next provider if any");
            } catch (\Throwable $e) {
                Log::warning("AI reply via {$provider} failed: " . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * One-shot completion with a custom system prompt (used for classification,
     * e.g. hot-lead detection). Returns null when no AI key is set or the call fails.
     *
     * @param array<int, array{role:string, content:string}> $messages
     */
    public function classify(string $system, array $messages): ?string
    {
        foreach ($this->providerChain() as $provider) {
            try {
                $reply = $this->dispatchText($provider, $system, $messages);
                if ($reply) {
                    return $reply;
                }
            } catch (\Throwable $e) {
                Log::warning("AI classify via {$provider} failed: " . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Look at an image or PDF the customer sent and answer about it, using the
     * business instructions as context. Returns null when AI is unavailable, the
     * media type is unsupported, or the call fails.
     */
    public function describeMedia(Chatbot $chatbot, ?string $caption, string $bytes, string $mime): ?string
    {
        $chain = $this->providerChain();
        if ($chain === []) {
            return null;
        }

        // Only images and PDFs can be understood visually.
        if (! str_starts_with($mime, 'image/') && $mime !== 'application/pdf') {
            return null;
        }

        $system = $this->systemPrompt($chatbot) . "\n\n"
            . "The customer has sent a file (an image or a PDF). Look at it carefully and respond helpfully, "
            . "in line with the business information above. If it is a bill, invoice, receipt, screenshot or photo, "
            . "you may read the visible details and answer about them. If you cannot read it clearly, say so politely "
            . "and ask the customer to resend or clarify. Never invent details that are not visible in the file.";

        $question = trim((string) $caption) !== ''
            ? trim((string) $caption)
            : 'Please look at the attached file and tell me what it is about, and help me with it.';

        foreach ($chain as $provider) {
            try {
                $reply = $this->dispatchVision($provider, $system, $question, $bytes, $mime);
                if ($reply) {
                    return $reply;
                }
            } catch (\Throwable $e) {
                Log::warning("AI media via {$provider} failed: " . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Look at an image/PDF and return a short description for a given
     * instruction. Chatbot-independent (used by the GBP AI post generator so the
     * AI can "see" an uploaded file). Returns null when no AI is configured.
     */
    public function visionDescribe(string $instruction, string $bytes, string $mime): ?string
    {
        $chain = $this->providerChain();
        if ($chain === [] || (! str_starts_with($mime, 'image/') && $mime !== 'application/pdf')) {
            return null;
        }

        foreach ($chain as $provider) {
            try {
                $reply = $this->dispatchVision($provider, 'You are a concise visual assistant.', $instruction, $bytes, $mime);
                if ($reply) {
                    return trim($reply);
                }
            } catch (\Throwable $e) {
                Log::warning("AI vision via {$provider} failed: " . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Ordered list of providers to try. The preferred one (AI_PROVIDER) comes
     * first; the other follows as a fallback. Only providers whose key is set are
     * included — so a single configured key still works, and when both are set a
     * failure on one rolls over to the other.
     *
     * @return array<int, string>
     */
    private function providerChain(): array
    {
        // When the admin has forced a single active provider, use only that one.
        if ($active = $this->settings()->activeProvider()) {
            return [$active];
        }

        // Otherwise auto-detect: every provider that has a key, preferred first.
        $available = [];
        foreach (AiSetting::PROVIDERS as $p) {
            if ($this->keyFor($p)) {
                $available[$p] = true;
            }
        }
        if ($available === []) {
            return [];
        }

        $pref = config('services.ai.provider');
        $order = in_array($pref, AiSetting::PROVIDERS, true)
            ? array_merge([$pref], array_values(array_diff(AiSetting::PROVIDERS, [$pref])))
            : AiSetting::PROVIDERS;

        return array_values(array_filter($order, fn ($p) => isset($available[$p])));
    }

    /** Which providers currently have a key (for diagnostics). */
    public function configuredProviders(): array
    {
        return $this->providerChain();
    }

    /**
     * Live-test one provider's key + model with a tiny prompt (for Settings).
     *
     * @return array{ok:bool, message?:string, sample?:string, model?:string}
     */
    public function testProvider(string $provider): array
    {
        if (! in_array($provider, AiSetting::PROVIDERS, true)) {
            return ['ok' => false, 'message' => 'Unknown provider.'];
        }
        if (! $this->keyFor($provider)) {
            return ['ok' => false, 'message' => "No API key set for {$provider}."];
        }

        try {
            $text = $this->dispatchText(
                $provider,
                'You are a connectivity test. Reply with a short friendly greeting only.',
                [['role' => 'user', 'content' => 'Say hello in five words.']],
            );
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return $text
            ? ['ok' => true, 'sample' => $text, 'model' => $this->modelFor($provider)]
            : ['ok' => false, 'message' => 'No response — check the API key and model name.'];
    }

    /** Is image generation available (Gemini or OpenAI key present)? */
    public function canGenerateImages(): bool
    {
        return (bool) ($this->keyFor('gemini') || $this->keyFor('openai'));
    }

    /**
     * Generate an image from a text prompt. Uses the active provider's image
     * engine when it supports images (Gemini or OpenAI), else any available key.
     * A reference image (image-to-image) prefers Gemini. Returns a `data:` URI
     * (base64) or null so callers can fall back to a placeholder.
     */
    public function generateImage(string $prompt, ?string $refBytes = null, ?string $refMime = null): ?string
    {
        $active = $this->settings()->activeProvider();

        // Image-to-image is Gemini-only; prefer it when a reference is supplied.
        if ($refBytes !== null && $this->keyFor('gemini')) {
            return $this->callGeminiImage($prompt, $refBytes, $refMime);
        }
        if ($active === 'openai' && $this->keyFor('openai')) {
            return $this->callOpenAIImage($prompt);
        }
        if ($this->keyFor('gemini')) {
            return $this->callGeminiImage($prompt, $refBytes, $refMime);
        }
        if ($this->keyFor('openai')) {
            return $this->callOpenAIImage($prompt);
        }

        return null;
    }

    /** Gemini image model (image-to-image when a reference is given). */
    private function callGeminiImage(string $prompt, ?string $refBytes = null, ?string $refMime = null): ?string
    {
        $apiKey = $this->keyFor('gemini');
        if (! $apiKey) {
            return null;
        }

        $model = config('services.gemini.image_model', 'gemini-2.5-flash-image');
        $url = self::GEMINI_ENDPOINT . $model . ':generateContent?key=' . urlencode($apiKey);

        // When a reference image is given, include it so the model edits/builds
        // on it (image-to-image) rather than generating purely from text.
        $parts = [['text' => $prompt]];
        if ($refBytes !== null && $refMime !== null && str_starts_with($refMime, 'image/')) {
            $parts[] = ['inline_data' => ['mime_type' => $refMime, 'data' => base64_encode($refBytes)]];
        }

        try {
            $res = Http::timeout(90)->post($url, [
                'contents' => [[
                    'role' => 'user',
                    'parts' => $parts,
                ]],
            ]);
        } catch (\Throwable $e) {
            Log::warning('AI image generation failed: ' . $e->getMessage());
            return null;
        }

        if ($res->failed()) {
            Log::warning('Gemini image API error', ['status' => $res->status(), 'body' => $res->json('error.message')]);
            return null;
        }

        // The image comes back as an inline_data part (base64). Grab the first one.
        foreach ($res->json('candidates.0.content.parts', []) as $part) {
            $data = $part['inline_data']['data'] ?? $part['inlineData']['data'] ?? null;
            if ($data) {
                $mime = $part['inline_data']['mime_type'] ?? $part['inlineData']['mimeType'] ?? 'image/png';

                return "data:{$mime};base64,{$data}";
            }
        }

        Log::warning('Gemini image API returned no image data', ['model' => $model]);

        return null;
    }

    /** @param array<int, array{role:string, content:string}> $messages */
    private function callGemini(string $system, array $messages): ?string
    {
        $model = $this->modelFor('gemini');
        $contents = array_map(fn ($m) => [
            'role' => $m['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $m['content']]],
        ], $messages);

        $apiKey = $this->keyFor('gemini');
        $url = self::GEMINI_ENDPOINT . $model . ':generateContent?key=' . urlencode($apiKey);

        $res = Http::timeout(30)
            ->post($url, [
                'system_instruction' => ['parts' => [['text' => $system]]],
                'contents' => $contents,
                // WhatsApp replies are short; a lower cap keeps generation quick.
                'generationConfig' => ['maxOutputTokens' => 400, 'temperature' => 0.7],
            ]);

        if ($res->failed()) {
            Log::warning('Gemini reply API error', ['status' => $res->status(), 'body' => $res->json('error.message')]);
            return null;
        }

        $text = $res->json('candidates.0.content.parts.0.text');

        return $text ? trim($text) : null;
    }

    private function callGeminiVision(string $system, string $question, string $bytes, string $mime): ?string
    {
        $model = $this->modelFor('gemini');
        $apiKey = $this->keyFor('gemini');
        $url = self::GEMINI_ENDPOINT . $model . ':generateContent?key=' . urlencode($apiKey);

        $res = Http::timeout(45)->post($url, [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => $question],
                    ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]],
                ],
            ]],
            'generationConfig' => ['maxOutputTokens' => 700, 'temperature' => 0.5],
        ]);

        if ($res->failed()) {
            Log::warning('Gemini vision API error', ['status' => $res->status(), 'body' => $res->json('error.message')]);
            return null;
        }

        $text = $res->json('candidates.0.content.parts.0.text');

        return $text ? trim($text) : null;
    }

    private function callAnthropicVision(string $system, string $question, string $bytes, string $mime): ?string
    {
        $media = $mime === 'application/pdf'
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($bytes)]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($bytes)]];

        $res = Http::withHeaders([
            'x-api-key' => $this->keyFor('anthropic'),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(45)->post(self::ANTHROPIC_ENDPOINT, [
            'model' => $this->modelFor('anthropic'),
            'max_tokens' => 700,
            'system' => $system,
            'messages' => [[
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => $question], $media],
            ]],
        ]);

        if ($res->failed()) {
            Log::warning('Anthropic vision API error', ['status' => $res->status(), 'body' => $res->json('error.message')]);
            return null;
        }

        foreach ($res->json('content', []) as $block) {
            if (($block['type'] ?? '') === 'text' && ! empty(trim($block['text'] ?? ''))) {
                return trim($block['text']);
            }
        }

        return null;
    }

    /** @param array<int, array{role:string, content:string}> $messages */
    private function callAnthropic(string $system, array $messages): ?string
    {
        $res = Http::withHeaders([
            'x-api-key' => $this->keyFor('anthropic'),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(30)->post(self::ANTHROPIC_ENDPOINT, [
            'model' => $this->modelFor('anthropic'),
            'max_tokens' => 600,
            'system' => $system,
            'output_config' => ['effort' => 'low'], // quick, chat-style replies
            'messages' => $messages,
        ]);

        if ($res->failed()) {
            Log::warning('AI reply API error', ['status' => $res->status(), 'body' => $res->json('error.message')]);
            return null;
        }

        // Return the first text block (thinking blocks, if any, are skipped).
        foreach ($res->json('content', []) as $block) {
            if (($block['type'] ?? '') === 'text' && ! empty(trim($block['text'] ?? ''))) {
                return trim($block['text']);
            }
        }

        return null;
    }

    /** @param array<int, array{role:string, content:string}> $messages */
    private function callOpenAI(string $system, array $messages): ?string
    {
        $key = $this->keyFor('openai');
        if (! $key) {
            return null;
        }

        $msgs = array_merge(
            [['role' => 'system', 'content' => $system]],
            array_map(fn ($m) => [
                'role' => $m['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => $m['content'],
            ], $messages),
        );

        $res = Http::withToken($key)->timeout(30)->post(self::OPENAI_CHAT_ENDPOINT, [
            'model' => $this->modelFor('openai'),
            'messages' => $msgs,
            'max_tokens' => 700,
            'temperature' => 0.7,
        ]);

        if ($res->failed()) {
            Log::warning('OpenAI reply API error', ['status' => $res->status(), 'body' => $res->json('error.message')]);
            return null;
        }

        $text = $res->json('choices.0.message.content');

        return $text ? trim($text) : null;
    }

    private function callOpenAIVision(string $system, string $question, string $bytes, string $mime): ?string
    {
        $key = $this->keyFor('openai');
        // OpenAI chat vision takes images via a data URL; PDFs are not supported here.
        if (! $key || $mime === 'application/pdf') {
            return null;
        }

        $res = Http::withToken($key)->timeout(45)->post(self::OPENAI_CHAT_ENDPOINT, [
            'model' => $this->modelFor('openai'),
            'max_tokens' => 700,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => $question],
                    ['type' => 'image_url', 'image_url' => ['url' => "data:{$mime};base64," . base64_encode($bytes)]],
                ]],
            ],
        ]);

        if ($res->failed()) {
            Log::warning('OpenAI vision API error', ['status' => $res->status(), 'body' => $res->json('error.message')]);
            return null;
        }

        $text = $res->json('choices.0.message.content');

        return $text ? trim($text) : null;
    }

    /** OpenAI image generation (gpt-image-1 by default). Returns a `data:` URI. */
    private function callOpenAIImage(string $prompt): ?string
    {
        $key = $this->keyFor('openai');
        if (! $key) {
            return null;
        }

        try {
            $res = Http::withToken($key)->timeout(90)->post(self::OPENAI_IMAGE_ENDPOINT, [
                'model' => config('services.openai.image_model', 'gpt-image-1'),
                'prompt' => $prompt,
                'size' => '1024x1024',
                'n' => 1,
            ]);
        } catch (\Throwable $e) {
            Log::warning('OpenAI image generation failed: ' . $e->getMessage());
            return null;
        }

        if ($res->failed()) {
            Log::warning('OpenAI image API error', ['status' => $res->status(), 'body' => $res->json('error.message')]);
            return null;
        }

        $b64 = $res->json('data.0.b64_json');
        if ($b64) {
            return 'data:image/png;base64,' . $b64;
        }

        // Some models (dall-e-3) return a hosted URL instead of base64.
        return $res->json('data.0.url') ?: null;
    }

    private function systemPrompt(Chatbot $chatbot): string
    {
        $business = trim((string) $chatbot->ai_instructions) ?: 'A business that uses WhatsApp to talk to its customers.';

        return implode("\n", [
            'You are the WhatsApp assistant for a business. Reply to the customer helpfully and concisely, like a friendly human agent on WhatsApp.',
            '',
            'BUSINESS INFORMATION & INSTRUCTIONS (your only source of truth):',
            $business,
            '',
            'RULES:',
            '- Answer only from the business information above. If the answer is not there or is out of scope, politely say you will connect them with a team member — do not make things up.',
            '- Never invent prices, offers, timings, or policies that are not stated above.',
            '- Reply in the same language the customer writes in (match Hindi / Hinglish / English).',
            '- Follow the tone, length, format and example replies given in the business instructions above.',
            '- Write WhatsApp-friendly plain text — emojis are fine; avoid markdown headings, tables or code blocks.',
        ]);
    }

    /**
     * Build a normalized messages array: recent turns (inbound=user,
     * outbound=assistant), consecutive same-role turns merged, starting with a
     * user turn, and always ending with the current incoming message.
     *
     * @return array<int, array{role:string, content:string}>
     */
    private function history(Conversation $conversation, string $incoming): array
    {
        $rows = Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->whereIn('type', ['text'])
            ->whereNotNull('body')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->reverse()
            ->values();

        $turns = [];
        foreach ($rows as $m) {
            $role = $m->direction === Message::DIRECTION_OUTBOUND ? 'assistant' : 'user';
            $text = trim((string) $m->body);
            if ($text === '') {
                continue;
            }
            if (! empty($turns) && $turns[count($turns) - 1]['role'] === $role) {
                $turns[count($turns) - 1]['content'] .= "\n" . $text;
            } else {
                $turns[] = ['role' => $role, 'content' => $text];
            }
        }

        // Ensure the very last turn is the current incoming customer message.
        if (empty($turns) || $turns[count($turns) - 1]['role'] !== 'user'
            || $turns[count($turns) - 1]['content'] !== trim($incoming)) {
            if (! empty($turns) && $turns[count($turns) - 1]['role'] === 'user') {
                $turns[count($turns) - 1]['content'] = trim($incoming);
            } else {
                $turns[] = ['role' => 'user', 'content' => trim($incoming)];
            }
        }

        // Drop any leading assistant turns — the array must start with a user turn.
        while (! empty($turns) && $turns[0]['role'] !== 'user') {
            array_shift($turns);
        }

        return $turns;
    }
}
