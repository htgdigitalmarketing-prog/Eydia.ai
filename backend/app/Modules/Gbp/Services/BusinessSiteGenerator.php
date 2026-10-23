<?php

namespace App\Modules\Gbp\Services;

use App\Models\BusinessSite;
use App\Models\GbpLocation;
use App\Models\GbpPhoto;
use App\Models\GbpReview;
use Illuminate\Support\Str;

/**
 * Builds a public business microsite + Google Business Profile report from a
 * connected location's data (profile, reviews, photos and health score). The
 * snapshot is frozen into the BusinessSite so the live page stays stable until
 * it is regenerated. One site per tenant+location; re-running refreshes it.
 */
class BusinessSiteGenerator
{
    public function __construct(private readonly GbpHealthService $health) {}

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
