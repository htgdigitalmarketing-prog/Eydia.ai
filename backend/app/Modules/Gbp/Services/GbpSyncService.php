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
    public function __construct(private GoogleGbpProvider $provider)
    {
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

        foreach ($accounts as $account) {
            $accountName = $account['name'] ?? null; // "accounts/123"
            if (! $accountName) {
                continue;
            }

            foreach ($this->provider->listLocations($integration, $accountName) as $loc) {
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
                $locationsSynced++;

                $reviewsSynced += $this->syncReviews($integration, $location);
                $this->recomputeAggregate($location);
            }
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
            GbpReview::updateOrCreate(
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
