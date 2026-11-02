<?php

namespace App\Modules\Gbp\Services;

use App\Models\GbpAutomation;
use App\Models\GbpLocation;
use App\Models\GbpPost;
use App\Models\GbpReview;
use App\Models\Integration;
use App\Modules\Billing\Services\CreditService;
use Illuminate\Support\Carbon;

/**
 * Runs ONE tenant's enabled Google Business Profile automations and reports what
 * it did. Shared by the hourly `gbp:run-automations` command (loops every tenant)
 * and the "Run now" button on the Automation page (one tenant, on demand), so the
 * behaviour is identical whether the scheduler fires it or the user does.
 */
class GbpAutomationRunner
{
    private const REPLIES_PER_RUN = 5;

    public function __construct(
        private readonly GbpAiService $ai,
        private readonly CreditService $credits,
        private readonly GoogleGbpProvider $provider,
    ) {
    }

    /**
     * @return array{replies:int, posts:int, images:int, skipped:?string}
     */
    public function run(GbpAutomation $a): array
    {
        $result = ['replies' => 0, 'posts' => 0, 'images' => 0, 'skipped' => null];

        // Nothing AI can do without a configured provider — say so clearly.
        if (($a->auto_review_replies || $a->auto_posts) && ! $this->ai->configured()) {
            $result['skipped'] = 'no_ai_provider';

            return $result;
        }

        if ($a->auto_review_replies) {
            $result['replies'] = $this->autoReply($a);
        }
        if ($a->auto_posts) {
            [$posts, $images] = $this->autoPosts($a);
            $result['posts'] = $posts;
            $result['images'] = $images;
        }

        $a->forceFill(['last_run_at' => now()])->saveQuietly();

        return $result;
    }

    private function autoReply(GbpAutomation $a): int
    {
        $integration = Integration::withoutGlobalScopes()
            ->where('tenant_id', $a->tenant_id)->where('provider', Integration::GOOGLE_GBP)->first();
        $connected = $integration && $integration->isConnected();

        $reviews = GbpReview::withoutGlobalScopes()
            ->where('tenant_id', $a->tenant_id)
            ->whereNull('reply')
            ->with('location')
            ->orderByDesc('reviewed_at')
            ->limit(self::REPLIES_PER_RUN)
            ->get();

        $done = 0;
        foreach ($reviews as $review) {
            if (! $this->credits->canAfford($a->tenant_id, 'ai_review_reply')) {
                break;
            }
            $location = $review->location;
            if (! $location) {
                continue;
            }

            $draft = $this->ai->replyToReview($location, (int) $review->rating, $review->comment, $a->reply_tone);
            if (! $draft) {
                break; // AI unavailable — stop trying this run
            }
            $this->credits->deduct($a->tenant_id, null, 'ai_review_reply', 'Auto review reply');

            if ($connected && $location->google_location_id && $review->google_review_id
                && ! str_starts_with((string) $review->google_review_id, 'demo-')) {
                $this->provider->putReply($integration, $location->google_location_id, $review->google_review_id, $draft);
            }
            $review->forceFill(['reply' => $draft, 'replied_at' => now()])->saveQuietly();
            $done++;
        }

        return $done;
    }

    /**
     * @return array{0:int, 1:int} [postsCreated, imagesGenerated]
     */
    private function autoPosts(GbpAutomation $a): array
    {
        $location = $a->location ?: GbpLocation::withoutGlobalScopes()
            ->where('tenant_id', $a->tenant_id)->orderByDesc('review_count')->first();
        if (! $location) {
            return [0, 0];
        }

        $scheduled = GbpPost::withoutGlobalScopes()
            ->where('tenant_id', $a->tenant_id)
            ->where('gbp_location_id', $location->id)
            ->where('status', 'SCHEDULED')
            ->where('scheduled_at', '>', now())
            ->count();

        $need = max(0, (int) $a->post_queue_target - $scheduled);
        if ($need <= 0 || ! $this->credits->canAfford($a->tenant_id, 'ai_caption')) {
            return [0, 0];
        }

        $ideas = $this->ai->planPosts($location, min($need, 4));
        if ($ideas === []) {
            return [0, 0];
        }
        $this->credits->deduct($a->tenant_id, null, 'ai_caption', 'Auto content plan');

        // Continue the cadence from the last scheduled post. The very first post
        // of an empty queue goes out in ~2 hours so the user sees activity soon,
        // then the chosen cadence spaces the rest.
        $lastAt = GbpPost::withoutGlobalScopes()
            ->where('tenant_id', $a->tenant_id)->where('gbp_location_id', $location->id)
            ->where('status', 'SCHEDULED')->max('scheduled_at');
        $next = $lastAt
            ? Carbon::parse($lastAt)->copy()->addDays($a->post_frequency_days)->setTime(10, 0)
            : now()->copy()->addHours(2);

        $created = 0;
        $images = 0;
        $wantImages = $a->auto_photos;
        foreach ($ideas as $idea) {
            $imageUrl = null;
            if ($wantImages && $this->credits->canAfford($a->tenant_id, 'ai_media_generate')) {
                $imageUrl = $this->ai->generatePostImage(
                    "Professional marketing image for {$location->name}. Theme: " . ($idea['title'] ?: $idea['summary']) . '. Clean, modern, no text overlay.'
                );
                if ($imageUrl) {
                    $this->credits->deduct($a->tenant_id, null, 'ai_media_generate', 'Auto post image');
                    $images++;
                }
            }

            GbpPost::withoutGlobalScopes()->create([
                'tenant_id' => $a->tenant_id,
                'gbp_location_id' => $location->id,
                'type' => $idea['type'],
                'summary' => $idea['summary'],
                'image_url' => $imageUrl,
                'status' => 'SCHEDULED',
                'scheduled_at' => $next->copy(),
                'meta' => $idea['title'] !== '' ? ['title' => $idea['title']] : null,
            ]);
            $created++;
            $next = $next->copy()->addDays($a->post_frequency_days)->setTime(10, 0);
        }

        return [$created, $images];
    }
}
