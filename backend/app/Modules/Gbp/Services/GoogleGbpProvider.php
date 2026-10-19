<?php

namespace App\Modules\Gbp\Services;

use App\Models\Integration;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to the real Google Business Profile APIs using a tenant's stored OAuth
 * token, refreshing the access token automatically. Google spreads this across
 * several hosts:
 *   - Account Management  : mybusinessaccountmanagement.googleapis.com/v1
 *   - Business Information : mybusinessbusinessinformation.googleapis.com/v1
 *   - Reviews / replies (legacy v4) : mybusiness.googleapis.com/v4
 *
 * Reviews & replies still live on the v4 endpoint; Google has not migrated them
 * to a v1 host. Access requires the business.manage scope on an approved project.
 */
class GoogleGbpProvider
{
    /** Human-readable reason the last call returned nothing, for the UI. */
    private ?string $lastError = null;

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public static function configured(): bool
    {
        return (bool) config('services.google.client_id') && (bool) config('services.google.client_secret');
    }

    /** Return a valid access token, refreshing if expired. Null if it can't. */
    private function token(Integration $integration): ?string
    {
        if ($integration->expires_at && $integration->expires_at->isFuture()) {
            return $integration->access_token;
        }

        if (! $integration->refresh_token) {
            return $integration->access_token; // best effort
        }

        $res = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $integration->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if (! $res->successful()) {
            Log::warning('GBP token refresh failed: ' . $res->body());

            // invalid_grant => the refresh token is dead (revoked, or the OAuth
            // consent screen is still in "Testing" where tokens expire in ~7d).
            // Retrying never helps; the tenant has to reconnect. Mark revoked so
            // isConnected() stops claiming a live connection.
            if ($res->json('error') === 'invalid_grant') {
                $integration->update(['access_token' => null, 'refresh_token' => null, 'expires_at' => null, 'status' => 'revoked']);
                $this->lastError = 'Your Google connection has expired or was revoked. Reconnect Google Business Profile to sync again.';
            } else {
                $this->lastError = 'Could not refresh the Google connection: ' . ($res->json('error_description') ?? $res->body());
            }

            return null;
        }

        $data = $res->json();
        $integration->update([
            'access_token' => $data['access_token'] ?? $integration->access_token,
            'expires_at' => isset($data['expires_in']) ? now()->addSeconds($data['expires_in']) : null,
            'status' => 'connected',
        ]);

        return $integration->access_token;
    }

    private function client(Integration $integration)
    {
        // A hard timeout matters: without one a slow Google endpoint can hang
        // the request past max_execution_time and make "Sync" spin forever.
        return Http::withToken($this->token($integration))->acceptJson()->timeout(15);
    }

    /** Pull a plain-English reason out of a failed Google API response. */
    private function describeFailure(Response $res): string
    {
        $message = $res->json('error.message');
        if (! $message) {
            return $res->status() . ' ' . $res->body();
        }

        return match ($res->json('error.status')) {
            'PERMISSION_DENIED' => "Google denied access ({$message}). Business Profile API access is likely still pending Google's approval for this project.",
            'UNAUTHENTICATED' => 'Your Google connection is no longer valid. Reconnect Google Business Profile.',
            default => $message,
        };
    }

    /** Business Profile accounts this Google user manages: [['name'=>'accounts/123', ...]]. */
    public function listAccounts(Integration $integration): array
    {
        $this->lastError = null;
        $res = $this->client($integration)->get('https://mybusinessaccountmanagement.googleapis.com/v1/accounts');

        if (! $res->successful()) {
            Log::warning('GBP listAccounts failed: ' . $res->status() . ' ' . $res->body());
            $this->lastError ??= $this->describeFailure($res);

            return [];
        }

        return $res->json('accounts', []);
    }

    /** Locations under an account ("accounts/123"); returns normalized rows. */
    public function listLocations(Integration $integration, string $accountName): array
    {
        $this->lastError = null;
        $url = "https://mybusinessbusinessinformation.googleapis.com/v1/{$accountName}/locations";
        $res = $this->client($integration)->get($url, [
            'readMask' => 'name,title,storefrontAddress,phoneNumbers,websiteUri,categories,metadata',
            'pageSize' => 100,
        ]);

        if (! $res->successful()) {
            Log::warning('GBP listLocations failed: ' . $res->status() . ' ' . $res->body());
            $this->lastError ??= $this->describeFailure($res);

            return [];
        }

        $out = [];
        foreach ($res->json('locations', []) as $loc) {
            $addr = '';
            if (isset($loc['storefrontAddress'])) {
                $a = $loc['storefrontAddress'];
                $parts = array_merge($a['addressLines'] ?? [], [$a['locality'] ?? '', $a['administrativeArea'] ?? '']);
                $addr = trim(implode(', ', array_filter($parts)), ', ');
            }
            $locName = $loc['name'] ?? '';
            // Business Information returns "locations/Y"; the Reviews API needs
            // "accounts/X/locations/Y". Prefix the account unless already present.
            $fullName = str_starts_with($locName, 'accounts/') ? $locName : $accountName . '/' . $locName;
            $out[] = [
                'google_name' => $fullName,
                'title' => $loc['title'] ?? 'Untitled location',
                'address' => $addr,
                'phone' => $loc['phoneNumbers']['primaryPhone'] ?? null,
                'website' => $loc['websiteUri'] ?? null,
                'category' => $loc['categories']['primaryCategory']['displayName'] ?? null,
                'is_verified' => (bool) ($loc['metadata']['hasVoiceOfMerchant'] ?? false),
            ];
        }

        return $out;
    }

    /** Reviews for a location ("accounts/123/locations/456"); normalized rows. */
    public function listReviews(Integration $integration, string $locationName): array
    {
        $this->lastError = null;
        $url = "https://mybusiness.googleapis.com/v4/{$locationName}/reviews";

        try {
            $res = $this->client($integration)->get($url);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning("GBP listReviews timed out for {$locationName}: " . $e->getMessage());
            $this->lastError ??= 'Google took too long to respond. Try again in a moment.';

            return [];
        }

        if (! $res->successful()) {
            Log::warning('GBP listReviews failed: ' . $res->status() . ' ' . $res->body());
            $this->lastError ??= $this->describeFailure($res);

            return [];
        }

        $starMap = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];
        $out = [];
        foreach ($res->json('reviews', []) as $r) {
            $out[] = [
                'reviewId' => $r['reviewId'] ?? ($r['name'] ?? uniqid()),
                'name' => $r['reviewer']['displayName'] ?? 'Anonymous',
                'photo' => $r['reviewer']['profilePhotoUrl'] ?? null,
                'rating' => $starMap[$r['starRating'] ?? 'THREE'] ?? 3,
                'comment' => $r['comment'] ?? '',
                'time' => isset($r['createTime']) ? date('Y-m-d H:i:s', strtotime($r['createTime'])) : now()->toDateTimeString(),
                'reply' => $r['reviewReply']['comment'] ?? null,
                'reply_time' => isset($r['reviewReply']['updateTime']) ? date('Y-m-d H:i:s', strtotime($r['reviewReply']['updateTime'])) : null,
            ];
        }

        return $out;
    }

    /** Post (or update) a reply to a review. */
    public function putReply(Integration $integration, string $locationName, string $reviewId, string $comment): bool
    {
        $url = "https://mybusiness.googleapis.com/v4/{$locationName}/reviews/{$reviewId}/reply";

        try {
            $res = $this->client($integration)->put($url, ['comment' => $comment]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning('GBP putReply timed out: ' . $e->getMessage());
            $this->lastError = 'Google took too long to respond. Try again in a moment.';

            return false;
        }

        if (! $res->successful()) {
            Log::warning('GBP putReply failed: ' . $res->body());
            $this->lastError = $this->describeFailure($res);

            return false;
        }

        return true;
    }

    /**
     * Publish a local post to a location (GBP v4 localPosts).
     * $post: ['summary','type','image_url'?,'cta_type'?,'cta_url'?].
     *
     * @return ?string the created Google post resource name, or null on failure
     */
    public function createPost(Integration $integration, string $locationName, array $post): ?string
    {
        $this->lastError = null;
        $body = [
            'languageCode' => 'en',
            'summary' => $post['summary'] ?? '',
            'topicType' => $post['type'] ?? 'STANDARD',
        ];
        if (! empty($post['image_url'])) {
            $body['media'] = [['mediaFormat' => 'PHOTO', 'sourceUrl' => $post['image_url']]];
        }
        if (! empty($post['cta_type']) && ($post['cta_type'] !== 'NONE')) {
            $body['callToAction'] = array_filter([
                'actionType' => $post['cta_type'],
                'url' => $post['cta_url'] ?? null,
            ]);
        }

        try {
            $res = $this->client($integration)->post("https://mybusiness.googleapis.com/v4/{$locationName}/localPosts", $body);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->lastError = 'Google took too long to respond. Try again in a moment.';

            return null;
        }

        if (! $res->successful()) {
            Log::warning('GBP createPost failed: ' . $res->body());
            $this->lastError = $this->describeFailure($res);

            return null;
        }

        return $res->json('name');
    }

    /** Delete a published local post by its Google resource name. */
    public function deletePost(Integration $integration, string $postName): bool
    {
        $res = $this->client($integration)->delete("https://mybusiness.googleapis.com/v4/{$postName}");
        if (! $res->successful()) {
            $this->lastError = $this->describeFailure($res);

            return false;
        }

        return true;
    }

    /**
     * Publish a photo to a location's media (GBP v4 media). Google fetches the
     * bytes from $sourceUrl, so it must be a publicly reachable URL.
     *
     * @return ?string the created media resource name, or null on failure
     */
    public function uploadPhoto(Integration $integration, string $locationName, string $sourceUrl, string $category = 'ADDITIONAL'): ?string
    {
        $this->lastError = null;
        $body = [
            'mediaFormat' => 'PHOTO',
            'locationAssociation' => ['category' => $category],
            'sourceUrl' => $sourceUrl,
        ];

        try {
            $res = $this->client($integration)->post("https://mybusiness.googleapis.com/v4/{$locationName}/media", $body);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->lastError = 'Google took too long to respond. Try again in a moment.';

            return null;
        }

        if (! $res->successful()) {
            Log::warning('GBP uploadPhoto failed: ' . $res->body());
            $this->lastError = $this->describeFailure($res);

            return null;
        }

        return $res->json('name');
    }

    /**
     * Daily performance metrics for a location over the last $days days
     * (Business Profile Performance API). Returns a normalized map of
     * metric => total, plus a per-day series. Null on failure.
     *
     * @return ?array{totals:array<string,int>, series:array<int,array>}
     */
    public function fetchInsights(Integration $integration, string $locationName, int $days = 30): ?array
    {
        $this->lastError = null;
        // locationName here is "accounts/x/locations/y"; the performance API
        // addresses the bare "locations/y" resource.
        $locId = preg_replace('#^accounts/[^/]+/#', '', $locationName);
        $metrics = [
            'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH', 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
            'BUSINESS_IMPRESSIONS_DESKTOP_MAPS', 'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
            'CALL_CLICKS', 'WEBSITE_CLICKS', 'BUSINESS_DIRECTION_REQUESTS',
        ];
        $end = now();
        $start = now()->subDays($days);
        $query = [
            'dailyRange.start_date.year' => $start->year, 'dailyRange.start_date.month' => $start->month, 'dailyRange.start_date.day' => $start->day,
            'dailyRange.end_date.year' => $end->year, 'dailyRange.end_date.month' => $end->month, 'dailyRange.end_date.day' => $end->day,
        ];
        foreach ($metrics as $m) {
            $query['dailyMetrics'][] = $m;
        }

        try {
            $res = $this->client($integration)->get(
                "https://businessprofileperformance.googleapis.com/v1/{$locId}:fetchMultiDailyMetricsTimeSeries",
                $query,
            );
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->lastError = 'Google took too long to respond. Try again in a moment.';

            return null;
        }

        if (! $res->successful()) {
            Log::warning('GBP fetchInsights failed: ' . $res->body());
            $this->lastError = $this->describeFailure($res);

            return null;
        }

        $totals = [];
        $byDate = [];
        foreach ($res->json('multiDailyMetricTimeSeries', []) as $block) {
            foreach ($block['dailyMetricTimeSeries'] ?? [] as $series) {
                $metric = $series['dailyMetric'] ?? 'UNKNOWN';
                $sum = 0;
                foreach ($series['timeSeries']['datedValues'] ?? [] as $dv) {
                    $v = (int) ($dv['value'] ?? 0);
                    $sum += $v;
                    $d = sprintf('%04d-%02d-%02d', $dv['date']['year'] ?? 0, $dv['date']['month'] ?? 0, $dv['date']['day'] ?? 0);
                    $byDate[$d] = ($byDate[$d] ?? 0) + $v;
                }
                $totals[$metric] = ($totals[$metric] ?? 0) + $sum;
            }
        }
        ksort($byDate);

        return [
            'totals' => $totals,
            'series' => array_map(fn ($d, $v) => ['date' => $d, 'value' => $v], array_keys($byDate), array_values($byDate)),
        ];
    }
}
