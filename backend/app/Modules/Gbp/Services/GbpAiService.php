<?php

namespace App\Modules\Gbp\Services;

use App\Models\GbpLocation;
use App\Modules\Chatbot\Services\AiReplyService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The AI behind the Google Business Profile automation:
 *   • rewrite the "from the business" description, optimised for local SEO;
 *   • plan a batch of varied posts to schedule;
 *   • generate + host an image for a post.
 *
 * All calls reuse the shared AiReplyService (Gemini/Anthropic) and return null/
 * empty when no provider is configured, so callers can degrade gracefully.
 */
class GbpAiService
{
    public function __construct(private readonly AiReplyService $ai) {}

    public function configured(): bool
    {
        return $this->ai->configuredProviders() !== [];
    }

    /** SEO-optimised "from the business" description. Null when AI is unavailable. */
    public function rewriteDescription(GbpLocation $location, array $keywords = []): ?string
    {
        $kw = $keywords !== []
            ? ' Target keywords (use naturally, no stuffing): ' . implode(', ', $keywords) . '.'
            : '';

        $system = 'You are a local-SEO copywriter for Google Business Profiles. Write a compelling '
            . '"from the business" description: 1–2 short paragraphs, about 600–750 characters, with '
            . 'natural local keywords, the city, and the main services, ending in a soft call to action. '
            . 'Plain text only — no markdown, no hashtags, no quotes around the text.';
        $user = 'Write the description.' . $this->facts($location) . $kw;

        $text = $this->ai->classify($system, [['role' => 'user', 'content' => $user]]);

        return $text ? trim($text) : null;
    }

    /**
     * Plan N varied posts as [{title, type, summary}]. Empty when AI unavailable.
     *
     * @return array<int, array{title:string, type:string, summary:string}>
     */
    public function planPosts(GbpLocation $location, int $count, array $keywords = []): array
    {
        $kw = $keywords !== []
            ? ' Weave in these themes/keywords where natural: ' . implode(', ', $keywords) . '.'
            : '';

        $system = 'You plan Google Business Profile content. Return ONLY JSON.';
        $user = "Create {$count} varied Google Business Profile posts for this business." . $this->facts($location) . $kw
            . ' Mix the post types (STANDARD updates, OFFER, EVENT). Each summary must be under 700 characters, '
            . 'engaging, plain text, a few tasteful emojis allowed, no hashtags. '
            . 'Return {"posts":[{"title":"short label","type":"STANDARD|OFFER|EVENT","summary":"the post body"}]}.';

        $parsed = $this->json($system, $user, 1400);
        $posts = is_array($parsed) && ! empty($parsed['posts']) && is_array($parsed['posts']) ? $parsed['posts'] : [];

        $out = [];
        foreach ($posts as $p) {
            $summary = trim((string) ($p['summary'] ?? ''));
            if ($summary === '') {
                continue;
            }
            $type = strtoupper((string) ($p['type'] ?? 'STANDARD'));
            if (! in_array($type, ['STANDARD', 'OFFER', 'EVENT', 'ALERT'], true)) {
                $type = 'STANDARD';
            }
            $out[] = [
                'title' => trim((string) ($p['title'] ?? '')),
                'type' => $type,
                'summary' => Str::limit($summary, 1480, ''),
            ];
        }

        return array_slice($out, 0, $count);
    }

    /** Generate an image for a post and host it on the public disk; returns a URL or null. */
    public function generatePostImage(string $prompt): ?string
    {
        $dataUri = $this->ai->generateImage($prompt);
        if (! $dataUri || ! str_starts_with($dataUri, 'data:')) {
            return null;
        }

        return $this->storeDataUri($dataUri);
    }

    private function storeDataUri(string $dataUri): ?string
    {
        if (! preg_match('/^data:(image\/[a-z.+-]+);base64,(.+)$/is', $dataUri, $m)) {
            return null;
        }
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/webp' => 'webp'][$m[1]] ?? 'png';
        $bytes = base64_decode($m[2], true);
        if ($bytes === false) {
            return null;
        }

        $path = 'gbp/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.' . $ext;
        try {
            Storage::disk('public')->put($path, $bytes);

            return url(Storage::disk('public')->url($path));
        } catch (\Throwable $e) {
            Log::warning('gbp ai image store failed: ' . $e->getMessage());

            return null;
        }
    }

    /** Compact business facts for the prompt, pulled from the saved profile. */
    private function facts(GbpLocation $location): string
    {
        $meta = $location->meta ?? [];
        $f = ['Business: ' . ($location->name ?: 'this business')];

        if ($location->category) {
            $f[] = 'Category: ' . $location->category;
        }
        $city = ($meta['service_area'][0] ?? null) ?: GbpProfileService::cityFromAddress($location->address);
        if ($city) {
            $f[] = 'City: ' . $city;
        }
        if (! empty($meta['description'])) {
            $f[] = 'Current description: ' . Str::limit((string) $meta['description'], 300);
        }
        $highlights = [];
        foreach ((array) ($meta['attributes'] ?? []) as $items) {
            foreach ((array) $items as $it) {
                $highlights[] = $it;
            }
        }
        if ($highlights !== []) {
            $f[] = 'Highlights: ' . implode(', ', array_slice($highlights, 0, 8));
        }
        if ((float) $location->rating > 0) {
            $f[] = 'Rating: ' . number_format((float) $location->rating, 1) . "★ ({$location->review_count} reviews)";
        }

        return ' ' . implode('. ', $f) . '.';
    }

    /** Ask the model for strict JSON and decode it (tolerates code fences / prose). */
    private function json(string $system, string $user, int $maxTokens): ?array
    {
        $raw = $this->ai->classify($system, [['role' => 'user', 'content' => $user]]);
        if (! $raw) {
            return null;
        }
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }
}
