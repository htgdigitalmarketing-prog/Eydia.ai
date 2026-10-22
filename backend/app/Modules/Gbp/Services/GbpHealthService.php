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
                'label' => 'Connections', 'icon' => '🔗', 'color' => '#38bdf8',
                'score' => $connected ? 100 : 0,
                'detail' => $connected ? 'Google connected' : 'Not connected',
            ],
            'reviews' => [
                'label' => 'Reviews', 'icon' => '⭐', 'color' => '#f59e0b',
                'score' => $this->clamp($total >= 25 ? 100 : (int) round($total / 25 * 100)),
                'detail' => "{$total} review(s)",
            ],
            'rating' => [
                'label' => 'Average rating', 'icon' => '📈', 'color' => '#22c55e',
                'score' => $avg >= 4.5 ? 100 : ($avg >= 4.0 ? 85 : ($avg >= 3.0 ? 55 : ($avg > 0 ? 25 : 0))),
                'detail' => $total ? round($avg, 2) . '★ avg' : 'No ratings',
            ],
            'reply_rate' => [
                'label' => 'Replies', 'icon' => '💬', 'color' => '#4c57d8',
                'score' => $this->clamp((int) round($replyRate * 100)),
                'detail' => $total ? round($replyRate * 100) . '% replied' : 'No reviews yet',
            ],
            'posts' => [
                'label' => 'Google Posts', 'icon' => '📝', 'color' => '#8b5cf6',
                'score' => $this->clamp($postsPublished >= 4 ? 100 : (int) round($postsPublished / 4 * 100)),
                'detail' => "{$postsPublished} published",
            ],
            'photos' => [
                'label' => 'Photos', 'icon' => '📷', 'color' => '#ec4899',
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

    /**
     * Prioritized "What to Do Next" list. Each item keeps the legacy
     * {priority, text} keys and adds rich fields (icon, title, why, cta,
     * module) so the overview cockpit can render Beacon-style action rows.
     */
    private function actions(bool $connected, int $locations, int $total, float $avg, float $replyRate, int $negUnreplied, int $posts, int $photos): array
    {
        $pending = $total ? (int) round($total * (1 - $replyRate)) : 0;
        $a = [];

        if (! $connected) {
            $a[] = ['priority' => 'critical', 'icon' => '🔗', 'module' => 'Setup', 'cta' => 'Connect Google',
                'title' => 'Connect your Google Business Profile',
                'why' => 'Everything starts here — connect Google to pull live reviews, insights and posts.'];
        }
        if ($connected && $locations === 0) {
            $a[] = ['priority' => 'critical', 'icon' => '📍', 'module' => 'Setup', 'cta' => 'Add Location',
                'title' => 'Add your first business location',
                'why' => 'Locations are the foundation — add one to start tracking reviews and insights.'];
        }
        if ($negUnreplied > 0) {
            $a[] = ['priority' => 'high', 'icon' => '⚠️', 'module' => 'Reviews', 'cta' => 'View Negative',
                'title' => "Address {$negUnreplied} negative review" . ($negUnreplied > 1 ? 's' : ''),
                'why' => 'Negative reviews need prompt, empathetic replies to protect your reputation.'];
        }
        if ($pending > 0 && $replyRate < 0.8) {
            $a[] = ['priority' => 'high', 'icon' => '⭐', 'module' => 'Reviews', 'cta' => 'Open Reviews',
                'title' => "Reply to {$pending} pending review" . ($pending > 1 ? 's' : ''),
                'why' => 'Responding to reviews boosts local rankings and shows customers you care.'];
        }
        if ($avg > 0 && $avg < 4.0) {
            $a[] = ['priority' => 'high', 'icon' => '⬆️', 'module' => 'Reviews', 'cta' => 'Optimize',
                'title' => 'Improve average rating (currently ' . number_format($avg, 1) . '★)',
                'why' => 'Reply to all reviews and follow up with happy customers to raise your score.'];
        }
        if ($photos < 10) {
            $need = 10 - $photos;
            $a[] = ['priority' => 'medium', 'icon' => '📷', 'module' => 'Photos', 'cta' => 'Upload Photos',
                'title' => "Upload {$need} more photo" . ($need > 1 ? 's' : '') . ' to your profile',
                'why' => 'Businesses with 10+ photos get 35% more clicks on Google.'];
        }
        if ($posts < 4) {
            $a[] = ['priority' => 'medium', 'icon' => '📝', 'module' => 'Posts', 'cta' => 'Create Post',
                'title' => 'Create a Google Business post',
                'why' => 'Regular posts keep your profile fresh and visible in local search.'];
        }
        if ($total === 0) {
            $a[] = ['priority' => 'medium', 'icon' => '⭐', 'module' => 'Reviews', 'cta' => 'Open Reviews',
                'title' => 'Start collecting reviews',
                'why' => 'Ask recent customers to leave a Google review to build social proof.'];
        }

        return array_slice($a, 0, 6);
    }

    private function clamp(int $n): int
    {
        return max(0, min(100, $n));
    }
}
