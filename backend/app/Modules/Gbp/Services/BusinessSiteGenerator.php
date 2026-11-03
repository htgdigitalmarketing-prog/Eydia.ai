<?php

namespace App\Modules\Gbp\Services;

use App\Models\BusinessSite;
use App\Models\GbpLocation;
use App\Models\GbpPhoto;
use App\Models\GbpReview;
use App\Modules\Chatbot\Services\AiReplyService;
use Illuminate\Support\Str;

/**
 * Builds a public business microsite + Google Business Profile report from a
 * connected location's data (profile, reviews, photos and health score). The
 * snapshot is frozen into the BusinessSite so the live page stays stable until
 * it is regenerated. One site per tenant+location; re-running refreshes it.
 */
class BusinessSiteGenerator
{
    public function __construct(
        private readonly GbpHealthService $health,
        private readonly AiReplyService $ai,
    ) {}

    /**
     * Build a marketing microsite for a LEAD from just their business name (and
     * whatever they said about it) — no Google listing needed. AI writes the
     * category, tagline, about and services; the page renders as a clean,
     * shareable website. Used by the WhatsApp website-builder chatbot.
     */
    public function generateForLead(int $tenantId, string $name, string $info = ''): BusinessSite
    {
        $name = trim($name) ?: 'My Business';
        $plan = $this->leadContent($name, $info);

        $site = new BusinessSite(['tenant_id' => $tenantId]);
        $site->forceFill([
            'tenant_id' => $tenantId,
            'gbp_location_id' => null,
            'slug' => $this->uniqueSlug($name),
            'title' => $name,
            'status' => 'published',
            'data' => [
                'name' => $name,
                'category' => $plan['category'] ?? '',
                'tagline' => $plan['tagline'] ?? '',
                'about' => $plan['about'] ?? '',
                'services' => array_values($plan['services'] ?? []),
                'address' => '',
                'phone' => '',
                'website' => '',
                'rating' => 0,
                'review_count' => 0,
                'is_verified' => false,
                'is_lead' => true,
                'maps_url' => 'https://www.google.com/maps/search/?api=1&query=' . urlencode($name),
                'reviews' => [],
                'photos' => [],
                'report' => ['score' => 0, 'grade' => '', 'sub_scores' => [], 'actions' => []],
                'generated_at' => now()->toIso8601String(),
            ],
            'published_at' => now(),
        ])->save();

        return $site->fresh();
    }

    /**
     * AI-written site content for a lead. Degrades to simple defaults when AI is
     * unavailable so a site is still produced.
     *
     * @return array{category:string, tagline:string, about:string, services:array<int,string>}
     */
    private function leadContent(string $name, string $info): array
    {
        $system = 'You are a web copywriter building a small business website. Return ONLY JSON.';
        $user = "Business name: \"{$name}\"." . ($info !== '' ? " What they told us: \"{$info}\"." : '')
            . ' Infer the business and write its website content. Return '
            . '{"category":"the business category/industry in 1-3 words",'
            . '"tagline":"a short punchy hero tagline (max 10 words)",'
            . '"about":"2 short paragraphs (about 500-700 characters) describing the business, what it offers and why customers trust it, warm and professional, plain text",'
            . '"services":["5-6 key services or products, 2-4 words each"]}.';

        $raw = $this->ai->classify($system, [['role' => 'user', 'content' => $user]]);
        $parsed = null;
        if ($raw) {
            $s = strpos($raw, '{');
            $e = strrpos($raw, '}');
            if ($s !== false && $e !== false && $e > $s) {
                $parsed = json_decode(substr($raw, $s, $e - $s + 1), true);
            }
        }

        if (! is_array($parsed) || empty($parsed['about'])) {
            // AI off / failed — still ship a usable page.
            return [
                'category' => '',
                'tagline' => 'Welcome to ' . $name,
                'about' => "{$name} is here to serve you. Get in touch to learn more about what we offer.",
                'services' => [],
            ];
        }

        $clean = fn ($v) => array_values(array_filter(array_map(fn ($x) => trim((string) $x), is_array($v) ? $v : [])));

        return [
            'category' => trim((string) ($parsed['category'] ?? '')),
            'tagline' => trim((string) ($parsed['tagline'] ?? ('Welcome to ' . $name))),
            'about' => trim((string) $parsed['about']),
            'services' => array_slice($clean($parsed['services'] ?? []), 0, 8),
        ];
    }

    public function generate(int $tenantId, GbpLocation $loc): BusinessSite
    {
        $existing = BusinessSite::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('gbp_location_id', $loc->id)
            ->first();

        $slug = $existing?->slug ?? $this->uniqueSlug($loc->name ?: 'business');

        $site = $existing ?? new BusinessSite(['tenant_id' => $tenantId]);
        $site->forceFill([
            'tenant_id' => $tenantId,
            'gbp_location_id' => $loc->id,
            'slug' => $slug,
            'title' => $loc->name ?: 'Business',
            'status' => 'published',
            'data' => $this->snapshot($loc),
            'published_at' => now(),
        ])->save();

        return $site->fresh();
    }

    public static function url(BusinessSite $site): string
    {
        return rtrim((string) config('app.url'), '/') . '/site/' . $site->slug;
    }

    /** @return array<string,mixed> */
    private function snapshot(GbpLocation $loc): array
    {
        $reviews = GbpReview::withoutGlobalScopes()
            ->where('gbp_location_id', $loc->id)
            ->whereNotNull('comment')
            ->orderByDesc('reviewed_at')
            ->limit(8)->get()
            ->map(fn (GbpReview $r) => [
                'author' => $r->author_name ?: 'Customer',
                'rating' => (int) $r->rating,
                'comment' => $r->comment,
                'date' => $r->reviewed_at?->format('M Y'),
            ])->all();

        $photos = GbpPhoto::withoutGlobalScopes()
            ->where('gbp_location_id', $loc->id)
            ->whereNotNull('image_url')
            ->orderByDesc('created_at')
            ->limit(9)->pluck('image_url')->all();

        $query = urlencode(trim(($loc->name ?: '') . ' ' . ($loc->address ?: '')));

        return [
            'name' => $loc->name,
            'category' => $loc->category,
            'address' => $loc->address,
            'phone' => $loc->phone,
            'website' => $loc->website,
            'rating' => round((float) $loc->rating, 1),
            'review_count' => (int) $loc->review_count,
            'is_verified' => (bool) $loc->is_verified,
            'maps_url' => "https://www.google.com/maps/search/?api=1&query={$query}",
            'reviews' => $reviews,
            'photos' => $photos,
            'report' => $this->health->compute(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'business';
        $slug = $base;
        while (BusinessSite::withoutGlobalScopes()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . Str::lower(Str::random(5));
        }

        return $slug;
    }
}
