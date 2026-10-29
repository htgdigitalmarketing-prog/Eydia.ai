<?php

namespace App\Modules\Gbp\Controllers;

use App\Http\Controllers\Controller;
use App\Models\GbpLocation;
use App\Models\GbpPost;
use App\Models\Integration;
use App\Modules\Billing\Services\CreditService;
use App\Modules\Chatbot\Services\AiReplyService;
use App\Modules\Gbp\Services\GoogleGbpProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Google Business Profile posts (localPosts). Drafts are stored per location;
 * publishing pushes to Google via the Business Profile API when connected.
 */
class GbpPostController extends Controller
{
    public function __construct(
        private readonly AiReplyService $ai,
        private readonly CreditService $credits,
    ) {}

    /**
     * AI-generate a Google post body from a short topic (ReviewFlow §10).
     * Returns text for the compose form; credit-gated ('ai_caption').
     */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'topic' => ['required', 'string', 'max:500'],
            'type' => ['nullable', 'in:STANDARD,EVENT,OFFER,ALERT'],
            'location' => ['nullable', 'string'],
            'image_url' => ['nullable', 'url', 'max:1024'],
        ]);

        $tenantId = $request->user()->tenant_id;
        if ($tenantId && ! $this->credits->canAfford($tenantId, 'ai_caption')) {
            return $this->fail('You are out of AI credits (' . $this->credits->cost('ai_caption') . ' needed). Top up or upgrade your plan.', ['credits' => ['insufficient']], 402);
        }

        // Auto-fetch the connected Google Business Profile so the AI writes to the
        // real business (name, category, rating, location).
        $location = ! empty($data['location'])
            ? GbpLocation::where('uuid', $data['location'])->first()
            : GbpLocation::visible()->orderByDesc('review_count')->first();
        $business = $location?->name ?: 'our business';
        $facts = [];
        if ($location?->category) {
            $facts[] = "category: {$location->category}";
        }
        if ($location && (float) $location->rating > 0) {
            $facts[] = 'rating ' . number_format((float) $location->rating, 1) . "★ ({$location->review_count} reviews)";
        }
        if ($location?->address) {
            $facts[] = "located at {$location->address}";
        }
        $factsStr = $facts ? ' Business facts — ' . implode('; ', $facts) . '.' : '';

        // When the user attaches an image, let the AI "see" it and tailor the post.
        $imageNote = '';
        if (! empty($data['image_url'])) {
            $seen = $this->describeUploadedImage($data['image_url']);
            if ($seen) {
                $imageNote = ' The user attached an image showing: ' . $seen . ' Write the post to match this image.';
            }
        }

        $type = $data['type'] ?? 'STANDARD';

        $system = 'You write short, engaging Google Business Profile posts (max ~1400 characters, 1 short paragraph + optional 1 line CTA). '
            . 'Plain text, no markdown, a few tasteful emojis allowed, and END with a final line of 3–5 relevant, SEO-friendly local hashtags.';
        $user = "Business: {$business}.{$factsStr} Post type: {$type}. Topic: {$data['topic']}.{$imageNote} Write the post body only.";

        $text = $this->ai->classify($system, [['role' => 'user', 'content' => $user]]);
        $source = 'ai';

        if (! $text) {
            $source = 'fallback';
            $text = "{$data['topic']} — visit {$business} today! Get in touch to learn more.";
        } else {
            $this->credits->deduct($tenantId, $request->user()->id, 'ai_caption', 'AI GBP post caption');
        }

        // Guarantee SEO hashtags on the caption (append when the model left them out).
        $text = trim((string) $text);
        if ($location && ! str_contains($text, '#')) {
            $tags = app(\App\Modules\Gbp\Services\GbpAiService::class)->hashtagsFor($location);
            if ($tags !== '') {
                $text .= "\n\n" . $tags;
            }
        }

        return $this->ok(['text' => $text, 'source' => $source, 'credits' => $tenantId ? $this->credits->balance($tenantId) : null]);
    }

    /**
     * AI content plan: generate a batch of varied posts for the business and
     * schedule them, spread out by a cadence. Optionally generates + attaches an
     * AI image per post. Credit-gated (one 'ai_caption' for the plan, plus one
     * 'ai_media_generate' per image). The posts land as SCHEDULED and the
     * existing scheduler publishes them when due (to Google, once connected).
     */
    public function autoPlan(Request $request, \App\Modules\Gbp\Services\GbpAiService $ai): JsonResponse
    {
        $data = $request->validate([
            'location' => ['nullable', 'string'],
            'count' => ['nullable', 'integer', 'min:1', 'max:8'],
            'cadence_days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'start_at' => ['nullable', 'date'],
            'with_images' => ['sometimes', 'boolean'],
            'keywords' => ['sometimes', 'array'],
            'keywords.*' => ['string', 'max:120'],
        ]);

        $location = ! empty($data['location'])
            ? GbpLocation::where('uuid', $data['location'])->first()
            : GbpLocation::visible()->orderByDesc('review_count')->first();
        if (! $location) {
            return $this->fail('Add your business information first, then generate a content plan.', [], 422);
        }

        $tenantId = $request->user()->tenant_id;
        if ($tenantId && ! $this->credits->canAfford($tenantId, 'ai_caption')) {
            return $this->fail('You are out of AI credits. Top up or upgrade your plan to continue.', ['credits' => ['insufficient']], 402);
        }

        $count = $data['count'] ?? 4;
        $ideas = $ai->planPosts($location, $count, $data['keywords'] ?? []);
        if ($ideas === []) {
            return $this->fail('AI is not configured yet (set GEMINI_API_KEY or ANTHROPIC_API_KEY in the backend .env).', [], 422);
        }
        // One AI call planned the whole batch — charge once.
        $this->credits->deduct($tenantId, $request->user()->id, 'ai_caption', 'AI content plan (' . count($ideas) . ' posts)');

        $cadence = $data['cadence_days'] ?? 3;
        $start = (! empty($data['start_at']) ? \Illuminate\Support\Carbon::parse($data['start_at']) : now()->addDay())
            ->copy()->setTime(10, 0);
        $wantImages = (bool) ($data['with_images'] ?? false);

        $created = [];
        foreach ($ideas as $i => $idea) {
            $imageUrl = null;
            if ($wantImages && $tenantId && $this->credits->canAfford($tenantId, 'ai_media_generate')) {
                $imageUrl = $ai->generatePostImage(
                    "Professional, photorealistic marketing image for a Google Business Profile post. "
                    . "Business: {$location->name}" . ($location->category ? " ({$location->category})" : '') . '. '
                    . 'Theme: ' . ($idea['title'] ?: $idea['summary']) . '. Clean, modern, bright, no text overlay.'
                );
                if ($imageUrl) {
                    $this->credits->deduct($tenantId, $request->user()->id, 'ai_media_generate', 'AI post image');
                }
            }

            $post = GbpPost::create([
                'tenant_id' => $tenantId,
                'gbp_location_id' => $location->id,
                'type' => $idea['type'],
                'summary' => $idea['summary'],
                'image_url' => $imageUrl,
                'status' => 'SCHEDULED',
                'scheduled_at' => $start->copy()->addDays($i * $cadence),
                'meta' => $idea['title'] !== '' ? ['title' => $idea['title']] : null,
            ]);

            $created[] = $this->arr($post->load('location'));
        }

        return $this->ok([
            'posts' => $created,
            'created' => count($created),
            'with_images' => $wantImages,
            'source' => 'ai',
            'credits' => $tenantId ? $this->credits->balance($tenantId) : null,
            'message' => count($created) . ' posts generated and scheduled.',
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $query = GbpPost::query()->with('location:id,uuid,name')->latest();
        if ($loc = $request->query('location')) {
            $query->whereHas('location', fn ($q) => $q->where('uuid', $loc));
        }

        $posts = $query->limit(200)->get();

        return $this->ok([
            'posts' => $posts->map(fn (GbpPost $p) => $this->arr($p))->all(),
            'counts' => [
                'total' => $posts->count(),
                'published' => $posts->where('status', 'PUBLISHED')->count(),
                'scheduled' => $posts->where('status', 'SCHEDULED')->count(),
                'draft' => $posts->whereIn('status', ['DRAFT', 'FAILED'])->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location' => ['required', 'string'],
            'summary' => ['required', 'string', 'max:1500'],
            'type' => ['nullable', 'in:STANDARD,EVENT,OFFER,ALERT'],
            'image_url' => ['nullable', 'url', 'max:1024'],
            'cta_type' => ['nullable', 'in:NONE,LEARN_MORE,CALL,ORDER,BOOK,SIGN_UP,SHOP'],
            'cta_url' => ['nullable', 'url', 'max:1024'],
            'schedule_at' => ['nullable', 'date'],
            // Offer / Event extras (stored in meta)
            'title' => ['nullable', 'string', 'max:255'],
            'coupon_code' => ['nullable', 'string', 'max:80'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'event_start' => ['nullable', 'date'],
            'event_end' => ['nullable', 'date', 'after_or_equal:event_start'],
        ]);
        $type = $data['type'] ?? 'STANDARD';
        $location = GbpLocation::where('uuid', $data['location'])->firstOrFail();

        $post = GbpPost::create([
            'tenant_id' => $request->user()->tenant_id,
            'gbp_location_id' => $location->id,
            'type' => $type,
            'summary' => $data['summary'],
            'image_url' => $data['image_url'] ?? null,
            'cta_type' => ($data['cta_type'] ?? 'NONE') === 'NONE' ? null : $data['cta_type'],
            'cta_url' => $data['cta_url'] ?? null,
            'status' => $this->statusFor($data['schedule_at'] ?? null),
            'scheduled_at' => $this->parseDate($data['schedule_at'] ?? null),
            'meta' => $this->buildMeta($data) ?: null,
        ]);

        return $this->ok(['post' => $this->arr($post->load('location'))], 201);
    }

    /** SCHEDULED when a future publish time is given, otherwise a DRAFT. */
    private function statusFor(?string $scheduleAt): string
    {
        $at = $this->parseDate($scheduleAt);

        return $at && $at->isFuture() ? 'SCHEDULED' : 'DRAFT';
    }

    private function parseDate(?string $v): ?\Illuminate\Support\Carbon
    {
        return ! empty($v) ? \Illuminate\Support\Carbon::parse($v) : null;
    }

    /** @param array<string,mixed> $data */
    private function buildMeta(array $data): array
    {
        return array_filter([
            'title' => $data['title'] ?? null,
            'coupon_code' => $data['coupon_code'] ?? null,
            'terms' => $data['terms'] ?? null,
            'event_start' => ($d = $this->parseDate($data['event_start'] ?? null)) ? $d->toIso8601String() : null,
            'event_end' => ($e = $this->parseDate($data['event_end'] ?? null)) ? $e->toIso8601String() : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $post = GbpPost::where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'summary' => ['sometimes', 'string', 'max:1500'],
            'type' => ['sometimes', 'in:STANDARD,EVENT,OFFER,ALERT'],
            'image_url' => ['nullable', 'url', 'max:1024'],
            'cta_type' => ['nullable', 'in:NONE,LEARN_MORE,CALL,ORDER,BOOK,SIGN_UP,SHOP'],
            'cta_url' => ['nullable', 'url', 'max:1024'],
        ]);
        if (array_key_exists('cta_type', $data)) {
            $data['cta_type'] = $data['cta_type'] === 'NONE' ? null : $data['cta_type'];
        }
        $post->update($data);

        return $this->ok(['post' => $this->arr($post->fresh('location'))]);
    }

    public function publish(Request $request, string $uuid, GoogleGbpProvider $provider): JsonResponse
    {
        $post = GbpPost::where('uuid', $uuid)->firstOrFail();
        $integration = $this->connected();

        if (! $integration || ! $post->location?->google_location_id) {
            return $this->fail('Connect your Google Business Profile to publish posts to Google.', [], 422);
        }

        $meta = $post->meta ?? [];
        $name = $provider->createPost($integration, $post->location->google_location_id, [
            'summary' => $post->summary,
            'type' => $post->type,
            'image_url' => $post->image_url,
            'cta_type' => $post->cta_type,
            'cta_url' => $post->cta_url,
            'title' => $meta['title'] ?? null,
            'coupon_code' => $meta['coupon_code'] ?? null,
            'terms' => $meta['terms'] ?? null,
            'event_start' => $meta['event_start'] ?? null,
            'event_end' => $meta['event_end'] ?? null,
        ]);

        if (! $name) {
            $post->update(['status' => 'FAILED']);

            return $this->fail($provider->getLastError() ?? 'Could not publish the post to Google.', [], 502);
        }

        $post->update(['status' => 'PUBLISHED', 'google_post_name' => $name, 'published_at' => now()]);

        return $this->ok(['post' => $this->arr($post->fresh('location'))]);
    }

    public function destroy(string $uuid, GoogleGbpProvider $provider): JsonResponse
    {
        $post = GbpPost::where('uuid', $uuid)->firstOrFail();
        if ($post->google_post_name && ($i = $this->connected())) {
            $provider->deletePost($i, $post->google_post_name);
        }
        $post->delete();

        return $this->ok(['message' => 'Post deleted.']);
    }

    private function connected(): ?Integration
    {
        $i = Integration::where('provider', Integration::GOOGLE_GBP)->first();

        return $i && $i->isConnected() ? $i : null;
    }

    /** Fetch an uploaded image and ask the AI what it shows (≤5 MB, images only). */
    private function describeUploadedImage(string $url): ?string
    {
        try {
            $res = \Illuminate\Support\Facades\Http::timeout(15)->get($url);
            if (! $res->successful()) {
                return null;
            }
            $bytes = (string) $res->body();
            $mime = (string) ($res->header('Content-Type') ?: 'image/jpeg');
            if (strlen($bytes) > 5 * 1024 * 1024 || ! str_starts_with($mime, 'image/')) {
                return null;
            }

            return $this->ai->visionDescribe(
                'In 1–2 short sentences, describe what this business photo shows (products, scene, any visible text/offer).',
                $bytes,
                $mime,
            );
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function arr(GbpPost $p): array
    {
        return [
            'id' => $p->uuid,
            'location' => $p->location ? ['id' => $p->location->uuid, 'name' => $p->location->name] : null,
            'type' => $p->type,
            'summary' => $p->summary,
            'image_url' => $p->image_url,
            'cta_type' => $p->cta_type,
            'cta_url' => $p->cta_url,
            'status' => $p->status,
            'published_at' => $p->published_at?->toIso8601String(),
            'scheduled_at' => $p->scheduled_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
            'title' => $p->meta['title'] ?? null,
            'coupon_code' => $p->meta['coupon_code'] ?? null,
            'terms' => $p->meta['terms'] ?? null,
            'event_start' => $p->meta['event_start'] ?? null,
            'event_end' => $p->meta['event_end'] ?? null,
        ];
    }
}
