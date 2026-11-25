<?php

namespace App\Modules\Gbp\Services;

use App\Models\GbpLocation;
use App\Models\GbpReview;
use App\Models\Integration;

/**
 * Pulls a tenant's Google Business Profile locations and reviews through the
 * GoogleGbpProvider and upserts them into gbp_locations / gbp_reviews so the
 * dashboard reads from cached rows. Idempotent: rows are matched by their
 * Google resource id, so re-syncing updates in place rather than duplicating.
 */
class GbpSyncService
{
    public function __construct(
        private GoogleGbpProvider $provider,
        private GbpSlotService $slots,
    ) {
    }

    /**
     * Sync every location + its reviews for the tenant's GBP integration.
     *
     * @return array{synced:int, locations:int, reviews:int, error:?string}
     */
    public function sync(Integration $integration): array
    {
        $locationsSynced = 0;
        $reviewsSynced = 0;

        $accounts = $this->provider->listAccounts($integration);
        if ($error = $this->provider->getLastError()) {
            return ['synced' => 0, 'locations' => 0, 'reviews' => 0, 'error' => $error];
        }

        // Locations the tenant has explicitly removed ("Remove" / "Only this")
        // must not come back on a re-sync, even though the connected (often
        // agency) Google account still manages them. Skip those entirely.
        $dismissed = (array) ($integration->meta['dismissed_locations'] ?? []);

        // Pass 1 — upsert every location (cheap: one listLocations call per
        // account, no per-location review fetch yet).
        $upserted = [];
        foreach ($accounts as $account) {
            $accountName = $account['name'] ?? null; // "accounts/123"
            if (! $accountName) {
                continue;
            }

            foreach ($this->provider->listLocations($integration, $accountName) as $loc) {
                if (in_array($loc['google_name'], $dismissed, true)) {
                    continue; // tenant removed this one — never re-create it
                }

                $location = GbpLocation::updateOrCreate(
                    [
                        'tenant_id' => $integration->tenant_id,
                        'google_location_id' => $loc['google_name'],
                    ],
                    [
                        'name' => $loc['title'],
                        'address' => $loc['address'] ?: null,
                        'phone' => $loc['phone'],
                        'website' => $loc['website'],
                        'category' => $loc['category'],
                        'is_verified' => $loc['is_verified'],
                        'last_synced_at' => now(),
                    ]
                );

                // Merge the auto-fetched profile extras. place_id + review link
                // always refresh; description/hours/service_area only fill when
                // empty so the user's own Business Info edits are preserved.
                $g = $loc['meta'] ?? [];
                if ($g !== []) {
                    $meta = $location->meta ?? [];
                    if (! empty($g['place_id'])) {
                        $meta['place_id'] = $g['place_id'];
                    }
                    // Always prefer Google's own "write a review" link (the short
                    // g.page/r/…/review URL) so Eydia's link matches Google's exactly.
                    if (! empty($g['review_url'])) {
                        $meta['review_url'] = $g['review_url'];
                    }
                    foreach (['description', 'hours', 'service_area'] as $k) {
                        if (! empty($g[$k]) && empty($meta[$k])) {
                            $meta[$k] = $g[$k];
                        }
                    }
                    $location->forceFill(['meta' => $meta])->save();
                }
                $locationsSynced++;
                $upserted[] = $location;
            }
        }

        // Reconcile plan-based slots: re-link slots to the fresh rows, bootstrap
        // existing/first-sync setups into slots, and hide anything not connected.
        // Visibility (is_hidden) now reflects the slot system.
        $this->slots->reconcile($integration->tenant_id);

        // Pass 2 — pull reviews only for the CONNECTED (visible) locations, capped
        // so a connected agency account with many locations never times out (504).
        $withReviews = GbpLocation::withoutGlobalScopes()
            ->where('tenant_id', $integration->tenant_id)
            ->where('is_hidden', false)
            ->orderByDesc('review_count')
            ->get()
            ->all();
        if ($withReviews === []) {
            $withReviews = $upserted; // nothing connected yet — fall back to all
        }
        $cap = 8;
        foreach (array_slice($withReviews, 0, $cap) as $location) {
            $reviewsSynced += $this->syncReviews($integration, $location);
            $this->recomputeAggregate($location);
        }

        $integration->update(['last_synced_at' => now()]);

        return [
            'synced' => $locationsSynced + $reviewsSynced,
            'locations' => $locationsSynced,
            'reviews' => $reviewsSynced,
            'error' => $this->provider->getLastError(),
        ];
    }

    private function syncReviews(Integration $integration, GbpLocation $location): int
    {
        $count = 0;
        foreach ($this->provider->listReviews($integration, $location->google_location_id) as $r) {
            $review = GbpReview::updateOrCreate(
                [
                    'gbp_location_id' => $location->id,
                    'google_review_id' => $r['reviewId'],
                ],
                [
                    'tenant_id' => $integration->tenant_id,
                    'author_name' => $r['name'],
                    'author_photo' => $r['photo'],
                    'rating' => $r['rating'],
                    'comment' => $r['comment'],
                    // Keep a locally-made reply if Google hasn't echoed one back yet.
                    'reply' => $r['reply'],
                    'replied_at' => $r['reply_time'],
                    'reviewed_at' => $r['time'],
                ]
            );

            // Push the mobile app a notification for genuinely new reviews (and
            // flag low-star ones so the owner can respond fast). Only for reviews
            // left in the last 2 days, so the first full import doesn't spam.
            $fresh = ! empty($r['time']) && \Illuminate\Support\Carbon::parse($r['time'])->gt(now()->subDays(2));
            if ($review->wasRecentlyCreated && $fresh) {
                try {
                    $rating = (int) $r['rating'];
                    $low = $rating > 0 && $rating <= 2;
                    app(\App\Modules\Mobile\Services\PushNotificationService::class)->notifyGbpReview(
                        $integration->tenant_id,
                        $low ? "⚠️ New {$rating}★ review" : "⭐ New review",
                        trim(($r['name'] ?: 'A customer') . ' left ' . ($rating ? $rating . '★' : 'a review') . ($r['comment'] ? ': ' . \Illuminate\Support\Str::limit($r['comment'], 80) : '')),
                        ['type' => $low ? 'gbp_review_low' : 'gbp_review', 'id' => $review->uuid],
                    );
                } catch (\Throwable $e) {
                    // notifications are non-critical
                }
            }
            $count++;
        }

        return $count;
    }

    private function recomputeAggregate(GbpLocation $location): void
    {
        $reviews = GbpReview::where('gbp_location_id', $location->id)->get(['rating']);
        $location->update([
            'review_count' => $reviews->count(),
            'rating' => $reviews->count() ? round($reviews->avg('rating'), 2) : 0,
        ]);
    }
}
