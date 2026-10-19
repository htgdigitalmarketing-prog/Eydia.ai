<?php

namespace App\Modules\Gbp\Controllers;

use App\Http\Controllers\Controller;
use App\Models\GbpLocation;
use App\Models\GbpReview;
use App\Models\Integration;
use App\Modules\Gbp\Services\GbpHealthService;
use App\Modules\Gbp\Services\GbpSyncService;
use App\Modules\Gbp\Services\GoogleGbpProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Google Business Profile / Reviews dashboard. Serves connected locations and
 * their reviews, and records replies. Live GBP API sync is wired per tenant;
 * cached rows are served here so the dashboard works before/after a sync.
 */
class GbpController extends Controller
{
    /** Dashboard overview: locations + aggregate rating stats. */
    public function overview(Request $request): JsonResponse
    {
        $locations = GbpLocation::query()->withCount('reviews')->get();
        $reviews = GbpReview::query()->get(['rating', 'reply']);

        $total = $reviews->count();
        $avg = $total ? round($reviews->avg('rating'), 2) : 0;
        $dist = [];
        for ($i = 5; $i >= 1; $i--) {
            $dist[$i] = $reviews->where('rating', $i)->count();
        }

        return $this->ok([
            'locations' => $locations->map(fn (GbpLocation $l) => $this->locationArray($l))->all(),
            'stats' => [
                'locations' => $locations->count(),
                'total_reviews' => $total,
                'average_rating' => $avg,
                'unreplied' => $reviews->whereNull('reply')->count(),
                'rating_distribution' => $dist,
            ],
        ]);
    }

    public function reviews(Request $request): JsonResponse
    {
        $query = GbpReview::query()->with('location:id,uuid,name')->latest('reviewed_at');

        if ($loc = $request->query('location')) {
            $query->whereHas('location', fn ($q) => $q->where('uuid', $loc));
        }
        if ($request->query('unreplied')) {
            $query->whereNull('reply');
        }
        if ($rating = $request->query('rating')) {
            $query->where('rating', (int) $rating);
        }

        $reviews = $query->limit(200)->get();

        return $this->ok([
            'reviews' => $reviews->map(fn (GbpReview $r) => $this->reviewArray($r))->all(),
        ]);
    }

    public function reply(Request $request, string $uuid, GoogleGbpProvider $provider): JsonResponse
    {
        $review = GbpReview::where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'reply' => ['required', 'string', 'max:4000'],
        ]);

        // When the tenant has a live GBP connection, push the reply to Google
        // first (reviews.updateReply). If that fails, surface the reason rather
        // than silently storing a reply Google never received.
        $integration = $this->connectedIntegration();
        if ($integration && $review->google_review_id && $review->location?->google_location_id) {
            $pushed = $provider->putReply(
                $integration,
                $review->location->google_location_id,
                $review->google_review_id,
                $data['reply'],
            );
            if (! $pushed) {
                return $this->fail(
                    $provider->getLastError() ?? 'Could not post the reply to Google. Please try again.',
                    [],
                    502,
                );
            }
        }

        $review->update(['reply' => $data['reply'], 'replied_at' => now()]);

        return $this->ok(['review' => $this->reviewArray($review->fresh('location'))]);
    }

    /** Re-sync locations & reviews from Google for this tenant. */
    public function sync(Request $request, GbpSyncService $sync): JsonResponse
    {
        $integration = $this->connectedIntegration();

        if (! $integration) {
            return $this->ok([
                'synced' => 0,
                'message' => 'Connect a Google Business Profile under Settings to sync locations and reviews.',
            ]);
        }

        $result = $sync->sync($integration);

        return $this->ok([
            'synced' => $result['synced'],
            'locations' => $result['locations'],
            'reviews' => $result['reviews'],
            'message' => $result['error']
                ?? ("Synced {$result['locations']} location(s) and {$result['reviews']} review(s) from Google."),
        ]);
    }

    /** Business Insights — live GBP performance metrics for a location. */
    public function insights(Request $request, GoogleGbpProvider $provider): JsonResponse
    {
        $days = max(7, min(90, (int) $request->query('days', 30)));
        $location = $this->pickLocation($request);
        $integration = $this->connectedIntegration();

        $labels = [
            'impressions' => 'Total impressions', 'CALL_CLICKS' => 'Calls',
            'WEBSITE_CLICKS' => 'Website clicks', 'BUSINESS_DIRECTION_REQUESTS' => 'Direction requests',
        ];

        if (! $integration || ! $location?->google_location_id) {
            return $this->ok([
                'connected' => false,
                'metrics' => [], 'series' => [], 'labels' => $labels,
                'message' => 'Connect your Google Business Profile to see live performance insights.',
            ]);
        }

        $data = $provider->fetchInsights($integration, $location->google_location_id, $days);
        if ($data === null) {
            return $this->fail($provider->getLastError() ?? 'Could not load insights from Google.', [], 502);
        }

        $t = $data['totals'];
        $impressions = ($t['BUSINESS_IMPRESSIONS_DESKTOP_SEARCH'] ?? 0) + ($t['BUSINESS_IMPRESSIONS_MOBILE_SEARCH'] ?? 0)
            + ($t['BUSINESS_IMPRESSIONS_DESKTOP_MAPS'] ?? 0) + ($t['BUSINESS_IMPRESSIONS_MOBILE_MAPS'] ?? 0);

        return $this->ok([
            'connected' => true,
            'days' => $days,
            'location' => ['id' => $location->uuid, 'name' => $location->name],
            'metrics' => [
                'impressions' => $impressions,
                'CALL_CLICKS' => $t['CALL_CLICKS'] ?? 0,
                'WEBSITE_CLICKS' => $t['WEBSITE_CLICKS'] ?? 0,
                'BUSINESS_DIRECTION_REQUESTS' => $t['BUSINESS_DIRECTION_REQUESTS'] ?? 0,
            ],
            'series' => $data['series'],
            'labels' => $labels,
        ]);
    }

    /** CSV export of the daily insights series. */
    public function insightsCsv(Request $request, GoogleGbpProvider $provider): StreamedResponse
    {
        $days = max(7, min(90, (int) $request->query('days', 30)));
        $location = $this->pickLocation($request);
        $integration = $this->connectedIntegration();
        $rows = ($integration && $location?->google_location_id)
            ? ($provider->fetchInsights($integration, $location->google_location_id, $days)['series'] ?? [])
            : [];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['date', 'interactions']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['date'], $r['value']]);
            }
            fclose($out);
        }, 'gbp-insights.csv', ['Content-Type' => 'text/csv']);
    }

    /** Google Audit / Health Score for the tenant's profile. */
    public function health(GbpHealthService $health): JsonResponse
    {
        return $this->ok($health->compute());
    }

    private function pickLocation(Request $request): ?GbpLocation
    {
        if ($loc = $request->query('location')) {
            return GbpLocation::where('uuid', $loc)->first();
        }

        return GbpLocation::query()->first();
    }

    /** The tenant's live Google Business Profile connection, or null. */
    private function connectedIntegration(): ?Integration
    {
        $integration = Integration::where('provider', Integration::GOOGLE_GBP)->first();

        return $integration && $integration->isConnected() ? $integration : null;
    }

    private function locationArray(GbpLocation $l): array
    {
        return [
            'id' => $l->uuid,
            'name' => $l->name,
            'address' => $l->address,
            'phone' => $l->phone,
            'website' => $l->website,
            'category' => $l->category,
            'rating' => (float) $l->rating,
            'review_count' => $l->review_count,
            'is_verified' => $l->is_verified,
            'last_synced_at' => $l->last_synced_at?->toIso8601String(),
        ];
    }

    private function reviewArray(GbpReview $r): array
    {
        return [
            'id' => $r->uuid,
            'location' => $r->location ? ['id' => $r->location->uuid, 'name' => $r->location->name] : null,
            'author_name' => $r->author_name,
            'author_photo' => $r->author_photo,
            'rating' => $r->rating,
            'comment' => $r->comment,
            'reply' => $r->reply,
            'replied_at' => $r->replied_at?->toIso8601String(),
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
        ];
    }
}
