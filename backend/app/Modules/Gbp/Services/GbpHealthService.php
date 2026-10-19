<?php

namespace App\Modules\Gbp\Services;

use App\Models\GbpLocation;
use App\Models\GbpPhoto;
use App\Models\GbpPost;
use App\Models\GbpReview;
use App\Models\Integration;

/**
 * Computes a 0–100 Google Business Profile health score for the tenant from
 * cached data (no AI, no external calls), broken into sub-scores, and a
 * prioritized "do next" action list. Mirrors the ReviewFlow audit/health model.
 */
class GbpHealthService
{
    /** @return array{score:int, grade:string, sub_scores:array, actions:array} */
    public function compute(): array
    {
        $connected = (bool) Integration::where('provider', Integration::GOOGLE_GBP)->first()?->isConnected();
        $locations = GbpLocation::query()->count();
        $reviews = GbpReview::query()->get(['rating', 'reply']);
        $total = $reviews->count();
        $avg = $total ? (float) $reviews->avg('rating') : 0.0;
        $replied = $reviews->whereNotNull('reply')->count();
        $replyRate = $total ? $replied / $total : 0.0;
        $negatives = $reviews->where('rating', '<=', 2);
        $negUnreplied = $negatives->whereNull('reply')->count();
        $postsPublished = GbpPost::where('status', 'PUBLISHED')->count();
        $photos = GbpPhoto::query()->count();

        $sub = [
            'connection' => [
                'label' => 'Google connected',
                'score' => $connected ? 100 : 0,
                'detail' => $connected ? 'Connected' : 'Not connected',
            ],
            'reviews' => [
                'label' => 'Reviews collected',
                'score' => $this->clamp($total >= 25 ? 100 : (int) round($total / 25 * 100)),
                'detail' => "{$total} review(s)",
            ],
            'rating' => [
                'label' => 'Average rating',
                'score' => $avg >= 4.5 ? 100 : ($avg >= 4.0 ? 85 : ($avg >= 3.0 ? 55 : ($avg > 0 ? 25 : 0))),
                'detail' => $total ? round($avg, 2) . '★' : 'No ratings',
            ],
            'reply_rate' => [
                'label' => 'Replying to reviews',
                'score' => $this->clamp((int) round($replyRate * 100)),
                'detail' => $total ? round($replyRate * 100) . '% replied' : 'No reviews yet',
            ],
            'posts' => [
                'label' => 'Google posts',
                'score' => $this->clamp($postsPublished >= 4 ? 100 : (int) round($postsPublished / 4 * 100)),
                'detail' => "{$postsPublished} published",
            ],
            'photos' => [
                'label' => 'Photos',
                'score' => $this->clamp($photos >= 10 ? 100 : (int) round($photos / 10 * 100)),
                'detail' => "{$photos} photo(s)",
            ],
        ];

        $overall = (int) round(collect($sub)->avg('score'));

        return [
            'score' => $overall,
            'grade' => $overall >= 85 ? 'Excellent' : ($overall >= 65 ? 'Good' : ($overall >= 40 ? 'Needs work' : 'Poor')),
            'sub_scores' => array_values(array_map(fn ($k, $v) => $v + ['key' => $k], array_keys($sub), $sub)),
            'actions' => $this->actions($connected, $locations, $total, $avg, $replyRate, $negUnreplied, $postsPublished, $photos),
        ];
    }

    private function actions(bool $connected, int $locations, int $total, float $avg, float $replyRate, int $negUnreplied, int $posts, int $photos): array
    {
        $a = [];
        if (! $connected) $a[] = ['priority' => 'critical', 'text' => 'Connect your Google Business Profile to sync live data.'];
        if ($connected && $locations === 0) $a[] = ['priority' => 'critical', 'text' => 'Add your first business location.'];
        if ($negUnreplied > 0) $a[] = ['priority' => 'high', 'text' => "Reply to {$negUnreplied} negative review(s) — these hurt most."];
        if ($total > 0 && $replyRate < 0.8) $a[] = ['priority' => 'high', 'text' => 'Reply to all pending reviews (aim for 80%+ reply rate).'];
        if ($avg > 0 && $avg < 4.0) $a[] = ['priority' => 'high', 'text' => 'Improve your average rating — follow up with happy customers for reviews.'];
        if ($posts < 4) $a[] = ['priority' => 'medium', 'text' => 'Publish Google posts weekly (at least 4 live).'];
        if ($photos < 10) $a[] = ['priority' => 'medium', 'text' => 'Upload more photos (target 10+) to boost engagement.'];
        if ($total === 0) $a[] = ['priority' => 'medium', 'text' => 'Start collecting reviews — ask customers to leave a Google review.'];

        return array_slice($a, 0, 6);
    }

    private function clamp(int $n): int
    {
        return max(0, min(100, $n));
    }
}
