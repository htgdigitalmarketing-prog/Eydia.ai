<?php

namespace App\Console\Commands;

use App\Models\GbpAutomation;
use App\Models\GbpLocation;
use App\Models\GbpPost;
use App\Models\GbpReview;
use App\Models\Integration;
use App\Modules\Billing\Services\CreditService;
use App\Modules\Gbp\Services\GbpAiService;
use App\Modules\Gbp\Services\GoogleGbpProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Runs the enabled Google Business Profile automations for every tenant:
 *   • auto_review_replies — AI-reply to unanswered reviews (and post when a live
 *     Google connection exists);
 *   • auto_posts — keep the scheduled-post queue topped up at the chosen cadence,
 *     optionally with an AI image when auto_photos is on.
 *
 * Each feature respects its ON/OFF switch and the tenant's AI credits, with small
 * per-run caps so one run never drains a wallet. Scheduled hourly.
 */
class RunGbpAutomations extends Command
{
    protected $signature = 'gbp:run-automations';
    protected $description = 'Run enabled GBP automations (AI review replies, scheduled posts)';

    private const REPLIES_PER_RUN = 5;

    public function handle(GbpAiService $ai, CreditService $credits, GoogleGbpProvider $provider): int
    {
        $rows = GbpAutomation::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('auto_review_replies', true)->orWhere('auto_posts', true))
            ->get();

        foreach ($rows as $automation) {
            try {
                if ($automation->auto_review_replies) {
                    $this->autoReply($automation, $ai, $credits, $provider);
                }
                if ($automation->auto_posts) {
                    $this->autoPosts($automation, $ai, $credits);
                }
                $automation->forceFill(['last_run_at' => now()])->saveQuietly();
            } catch (\Throwable $e) {
                Log::warning('gbp automation failed for tenant ' . $automation->tenant_id . ': ' . $e->getMessage());
            }
        }

        $this->info('GBP automations run for ' . $rows->count() . ' tenant(s).');

        return self::SUCCESS;
    }

    private function autoReply(GbpAutomation $a, GbpAiService $ai, CreditService $credits, GoogleGbpProvider $provider): void
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

        foreach ($reviews as $review) {
            if (! $credits->canAfford($a->tenant_id, 'ai_review_reply')) {
                break;
            }
            $location = $review->location;
            if (! $location) {
                continue;
            }

            $draft = $ai->replyToReview($location, (int) $review->rating, $review->comment, $a->reply_tone);
            if (! $draft) {
                break; // AI unavailable — stop trying this run
            }
            $credits->deduct($a->tenant_id, null, 'ai_review_reply', 'Auto review reply');

            // Post to Google when connected & linked, otherwise store the reply.
            if ($connected && $location->google_location_id && $review->google_review_id
                && ! str_starts_with((string) $review->google_review_id, 'demo-')) {
                $provider->putReply($integration, $location->google_location_id, $review->google_review_id, $draft);
            }
            $review->forceFill(['reply' => $draft, 'replied_at' => now()])->saveQuietly();
        }
    }

    private function autoPosts(GbpAutomation $a, GbpAiService $ai, CreditService $credits): void
    {
        $location = $a->location ?: GbpLocation::withoutGlobalScopes()
            ->where('tenant_id', $a->tenant_id)->orderByDesc('review_count')->first();
        if (! $location) {
            return;
        }

        $scheduled = GbpPost::withoutGlobalScopes()
            ->where('tenant_id', $a->tenant_id)
            ->where('gbp_location_id', $location->id)
            ->where('status', 'SCHEDULED')
            ->where('scheduled_at', '>', now())
            ->count();

        $need = max(0, (int) $a->post_queue_target - $scheduled);
        if ($need <= 0 || ! $credits->canAfford($a->tenant_id, 'ai_caption')) {
            return;
        }

        $ideas = $ai->planPosts($location, min($need, 4));
        if ($ideas === []) {
            return;
        }
        $credits->deduct($a->tenant_id, null, 'ai_caption', 'Auto content plan');

        // Continue the cadence from the last scheduled post.
        $lastAt = GbpPost::withoutGlobalScopes()
            ->where('tenant_id', $a->tenant_id)->where('gbp_location_id', $location->id)
            ->where('status', 'SCHEDULED')->max('scheduled_at');
        $next = ($lastAt ? \Illuminate\Support\Carbon::parse($lastAt) : now())
            ->copy()->addDays($a->post_frequency_days)->setTime(10, 0);

        $wantImages = $a->auto_photos;
        foreach ($ideas as $idea) {
            $imageUrl = null;
            if ($wantImages && $credits->canAfford($a->tenant_id, 'ai_media_generate')) {
                $imageUrl = $ai->generatePostImage(
                    "Professional marketing image for {$location->name}. Theme: " . ($idea['title'] ?: $idea['summary']) . '. Clean, modern, no text overlay.'
                );
                if ($imageUrl) {
                    $credits->deduct($a->tenant_id, null, 'ai_media_generate', 'Auto post image');
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
            $next = $next->copy()->addDays($a->post_frequency_days);
        }
    }
}
