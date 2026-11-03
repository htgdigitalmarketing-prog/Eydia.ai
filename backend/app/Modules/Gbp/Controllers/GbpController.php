<?php

namespace App\Modules\Gbp\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdReport;
use App\Models\BusinessSite;
use App\Models\GbpLocation;
use App\Models\GbpPhoto;
use App\Models\GbpPost;
use App\Models\GbpReview;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Modules\Billing\Services\CreditService;
use App\Modules\Chatbot\Services\AiReplyService;
use App\Modules\Gbp\Services\GbpHealthService;
use App\Modules\Gbp\Services\GbpSyncService;
use App\Modules\Gbp\Services\GoogleGbpProvider;
use App\Modules\Gbp\Services\SentimentService;
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
    public function __construct(
        private readonly AiReplyService $ai,
        private readonly CreditService $credits,
        private readonly SentimentService $sentiment,
    ) {}

    /** 402 when the tenant cannot afford an AI action; null when it can. */
    private function guardCredits(Request $request, string $action): ?JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        if ($tenantId && ! $this->credits->canAfford($tenantId, $action)) {
            return $this->fail(
                'You are out of AI credits (' . $this->credits->cost($action) . ' needed). Top up or upgrade your plan to continue.',
                ['credits' => ['insufficient']],
                402,
            );
        }

        return null;
    }
    /**
     * Rich dashboard overview — locations, aggregate rating stats, the business
     * health cockpit, prioritized "what to do next" actions, module summary
     * cards, analytics chart series (rating breakdown, directory split, replies,
     * monthly trends) and recent reviews. Mirrors the ReviewFlow overview.
     */
    public function overview(Request $request, GbpHealthService $health): JsonResponse
    {
        // Only the locations the tenant chose to manage here drive the dashboard;
        // hidden ones (e.g. other businesses the connected Google account can
        // access) are listed separately so they can be un-hidden.
        $locations = GbpLocation::visible()
            ->withCount('reviews')
            ->withAvg('reviews as reviews_avg_rating', 'rating')
            ->get();
        $visibleIds = $locations->pluck('id');
        $hidden = GbpLocation::query()->where('is_hidden', true)
            ->get(['id', 'uuid', 'name', 'review_count', 'is_hidden']);

        $reviews = GbpReview::query()->whereIn('gbp_location_id', $visibleIds)
            ->get(['rating', 'reply', 'sentiment', 'reviewed_at', 'replied_at']);

        $total = $reviews->count();
        $avg = $total ? round($reviews->avg('rating'), 2) : 0;
        $replied = $reviews->whereNotNull('reply')->count();
        $unreplied = $total - $replied;

        $dist = [];
        for ($i = 5; $i >= 1; $i--) {
            $dist[$i] = $reviews->where('rating', $i)->count();
        }

        // ---- Monthly trends (last 8 months): labels, avg, count, response, star breakdown ----
        $trendLabels = $trendAvg = $trendCount = $trendResp = [];
        $breakdown = [1 => [], 2 => [], 3 => [], 4 => [], 5 => []];
        foreach (range(7, 0) as $i) {
            $start = now()->startOfMonth()->subMonths($i);
            $end = (clone $start)->endOfMonth();
            $inMonth = $reviews->filter(fn ($r) => $r->reviewed_at && $r->reviewed_at->between($start, $end));

            $trendLabels[] = $start->format('M');
            $cnt = $inMonth->count();
            $trendCount[] = $cnt;
            $trendAvg[] = $cnt ? round($inMonth->avg('rating'), 2) : null;
            for ($s = 1; $s <= 5; $s++) {
                $breakdown[$s][] = $inMonth->where('rating', $s)->count();
            }
            $rep = $inMonth->filter(fn ($r) => $r->replied_at && $r->reviewed_at);
            $trendResp[] = $rep->count()
                ? round($rep->avg(fn ($r) => $r->reviewed_at->diffInHours($r->replied_at)) / 24, 1)
                : null;
        }

        $repliedAll = $reviews->filter(fn ($r) => $r->replied_at && $r->reviewed_at);
        $avgResponse = $repliedAll->count()
            ? round($repliedAll->avg(fn ($r) => $r->reviewed_at->diffInHours($r->replied_at)) / 24, 1)
            : 0;

        // ---- Cross-module counts for the summary cards ----
        $postsCount = GbpPost::query()->count();
        $photosCount = GbpPhoto::query()->count();
        $socialCount = SocialPost::query()->count();
        $leadsCount = Lead::query()->count();
        $leadsConverted = Lead::query()->where('stage', 'CONVERTED')->count();
        $adSpend = (float) AdReport::query()->sum('spend');

        $tenantId = $request->user()?->tenant_id;
        $creditBalance = $tenantId ? $this->credits->balance($tenantId) : 0;
        $plan = Subscription::query()->with('plan')->first()?->plan?->name ?? 'Starter';
        $lastSynced = $locations->max('last_synced_at');

        $recent = GbpReview::query()->whereIn('gbp_location_id', $visibleIds)
            ->with('location:id,uuid,name')
            ->orderByDesc('reviewed_at')->limit(5)->get();

        return $this->ok([
            'locations' => $locations->map(fn (GbpLocation $l) => $this->locationArray($l) + [
                'total_reviews' => (int) ($l->reviews_count ?? 0),
                'avg_rating' => round((float) ($l->reviews_avg_rating ?? 0), 1),
            ])->all(),
            'hidden_locations' => $hidden->map(fn (GbpLocation $l) => [
                'id' => $l->uuid, 'name' => $l->name, 'review_count' => (int) $l->review_count,
            ])->all(),
            'stats' => [
                'locations' => $locations->count(),
                'total_reviews' => $total,
                'average_rating' => $avg,
                'unreplied' => $unreplied,
                'replied' => $replied,
                'rating_distribution' => $dist,
            ],
            'health' => $health->compute(),
            'summary' => [
                'reviews' => $total,
                'avg_rating' => $avg,
                'posts' => $postsCount,
                'photos' => $photosCount,
                'social' => $socialCount,
                'leads' => $leadsCount,
                'leads_converted' => $leadsConverted,
                'ad_spend' => $adSpend,
                'credit_balance' => $creditBalance,
                'plan' => $plan,
                'last_synced_at' => $lastSynced?->toIso8601String(),
            ],
            'charts' => [
                'star_dist' => $dist,
                'directory' => ['Google' => $total, 'Facebook' => 0, 'Others' => 0],
                'replied' => $replied,
                'unreplied' => $unreplied,
                'trend_labels' => $trendLabels,
                'trend_avg' => $trendAvg,
                'trend_count' => $trendCount,
                'trend_resp' => $trendResp,
                'breakdown' => $breakdown,
                'avg_response' => $avgResponse,
            ],
            'recent_reviews' => $recent->map(fn (GbpReview $r) => $this->reviewArray($r))->all(),
        ]);
    }

    public function reviews(Request $request): JsonResponse
    {
        $query = GbpReview::query()->with('location:id,uuid,name')->latest('reviewed_at');

        if ($loc = $request->query('location')) {
            $query->whereHas('location', fn ($q) => $q->where('uuid', $loc));
        } else {
            // Default to the locations the tenant manages here, so after narrowing
            // to one business its reviews are all that show (not every location).
            $query->whereHas('location', fn ($q) => $q->visible());
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

    /**
     * Generate an AI draft reply for a review (ReviewFlow §8). The draft is
     * cached on the review; the user edits and posts it via reply(). Credit-gated
     * ('ai_review_reply'); falls back to a polite template when AI is unavailable.
     */
    public function suggestReply(Request $request, string $uuid): JsonResponse
    {
        if ($blocked = $this->guardCredits($request, 'ai_review_reply')) {
            return $blocked;
        }

        $review = GbpReview::with('location')->where('uuid', $uuid)->firstOrFail();
        $business = $review->location?->name ?: 'our business';
        $stars = (int) $review->rating;
        $comment = trim((string) $review->comment) ?: '(no comment text)';

        $system = implode("\n", [
            "You are the owner of \"{$business}\" replying to a Google review. Write ONE short, warm, professional reply (2-3 sentences).",
            '- Thank the reviewer by sentiment; address concerns in low-star reviews and offer to make it right.',
            '- Never be defensive or argumentative. No markdown, no hashtags, plain text only.',
            '- Reply in the language the review is written in.',
        ]);
        $user = "This is a {$stars}-star review from {$review->author_name}: \"{$comment}\". Write the owner's reply.";

        $draft = $this->ai->classify($system, [['role' => 'user', 'content' => $user]]);
        $source = 'ai';

        if (! $draft) {
            // Honest fallback so the feature still helps when AI is off.
            $source = 'fallback';
            $draft = $stars >= 4
                ? "Thank you so much for your kind words and {$stars}-star rating! We're delighted you had a great experience and look forward to serving you again."
                : "Thank you for your feedback, and we're sorry your experience fell short. We'd love the chance to make this right — please reach out to us directly so we can help.";
        }

        $review->update([
            'draft_reply' => $draft,
            'sentiment' => $review->sentiment ?: $this->sentiment->classify($review->comment),
        ]);

        if ($source === 'ai') {
            $this->credits->deduct($request->user()->tenant_id, $request->user()->id, 'ai_review_reply', 'AI review reply draft');
        }

        return $this->ok([
            'review' => $this->reviewArray($review->fresh('location')),
            'draft' => $draft,
            'source' => $source,
            'credits' => $this->credits->balance($request->user()->tenant_id),
        ]);
    }

    /**
     * One-Click Optimization (ReviewFlow §20): take the computed health score as a
     * factual anchor and ask the AI for a prioritized, business-specific action
     * plan. Credit-gated ('ai_optimize'); falls back to the health "do next" list.
     */
    public function optimize(Request $request, GbpHealthService $health): JsonResponse
    {
        if ($blocked = $this->guardCredits($request, 'ai_optimize')) {
            return $blocked;
        }

        $report = $health->compute();
        $business = GbpLocation::visible()->value('name') ?: 'this business';
        $subScores = collect($report['sub_scores'])
            ->map(fn ($s) => "{$s['label']}: {$s['detail']} ({$s['score']}/100)")
            ->implode('; ');

        $system = 'You are a local-SEO consultant optimizing a Google Business Profile. Return ONLY JSON.';
        $user = "Business: {$business}. Overall health {$report['score']}/100 ({$report['grade']}). Sub-scores: {$subScores}.\n"
            . 'Return {"summary":"2-3 sentences on the biggest opportunities","actions":[{"priority":"critical|high|medium|low","text":"one concrete step"}]} with 5-7 prioritized actions.';

        $raw = $this->ai->classify($system, [['role' => 'user', 'content' => $user]]);
        $parsed = $raw ? json_decode(trim(preg_replace('/```json|```/', '', $raw)), true) : null;

        if (is_array($parsed) && ! empty($parsed['actions'])) {
            $this->credits->deduct($request->user()->tenant_id, $request->user()->id, 'ai_optimize', 'One-click optimization');

            return $this->ok([
                'source' => 'ai',
                'summary' => $parsed['summary'] ?? 'Here is your prioritized optimization plan.',
                'actions' => $parsed['actions'],
                'health' => $report,
                'credits' => $this->credits->balance($request->user()->tenant_id),
            ]);
        }

        // Fallback: the deterministic "do next" list from the health service.
        return $this->ok([
            'source' => 'fallback',
            'summary' => "Your profile scores {$report['score']}/100 ({$report['grade']}). Work through these steps to improve it. (Configure an AI provider for a tailored plan.)",
            'actions' => $report['actions'],
            'health' => $report,
            'credits' => $this->credits->balance($request->user()->tenant_id),
        ]);
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

        // Fully-automatic first-time setup: right after the first successful sync,
        // if the primary business still has no description, let AI set up the whole
        // Business Information profile for it. Runs once (guarded by auto_setup_done)
        // and never overwrites fields the user has filled. Best-effort — a failure
        // here never fails the sync.
        $autoSetup = null;
        if (! $result['error']) {
            try {
                $autoSetup = $this->maybeAutoSetup($request);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('gbp auto-setup after sync failed: ' . $e->getMessage());
            }
        }

        return $this->ok([
            'synced' => $result['synced'],
            'locations' => $result['locations'],
            'reviews' => $result['reviews'],
            'auto_setup' => $autoSetup,
            'message' => $result['error']
                ?? ("Synced {$result['locations']} location(s) and {$result['reviews']} review(s) from Google."
                    . ($autoSetup ? ' AI set up your Business Information automatically.' : '')),
        ]);
    }

    /**
     * One-time AI auto-setup of the primary business profile, triggered after the
     * first sync when the description is still empty. Returns the list of fields
     * it filled, or null when it did nothing (already set up, no AI, no credits).
     */
    private function maybeAutoSetup(Request $request): ?array
    {
        $location = GbpLocation::visible()->orderByDesc('review_count')->first();
        if (! $location) {
            return null;
        }
        $meta = $location->meta ?? [];
        if (! empty($meta['description']) || ! empty($meta['auto_setup_done'])) {
            return null; // already has content or was set up before
        }

        $ai = app(\App\Modules\Gbp\Services\GbpAiService::class);
        if (! $ai->configured() || ! $this->credits->canAfford($request->user()->tenant_id, 'ai_caption')) {
            return null;
        }

        $plan = $ai->autoProfile($location);
        if (! $plan) {
            return null;
        }

        $profiles = app(\App\Modules\Gbp\Services\GbpProfileService::class);
        $changed = $profiles->applyAutoPlan($location, $plan);
        $this->credits->deduct($request->user()->tenant_id, $request->user()->id, 'ai_caption', 'AI auto-setup: business profile (after sync)');
        $location->forceFill(['meta' => array_merge($location->fresh()->meta ?? [], ['auto_setup_done' => true])])->save();

        // Best-effort live push to Google.
        $integration = $this->connectedIntegration();
        if ($integration && $location->google_location_id && $changed !== []) {
            $patch = $profiles->googlePatch($changed);
            if ($patch['mask'] !== '') {
                app(GoogleGbpProvider::class)->updateLocation($integration, $location->google_location_id, $patch['body'], $patch['mask']);
            }
        }

        return $changed !== [] ? array_keys($changed) : null;
    }

    /** Business Insights — live GBP performance metrics for a location. */
    public function insights(Request $request, GoogleGbpProvider $provider): JsonResponse
    {
        $days = max(1, min(540, (int) $request->query('days', 30)));
        [$start, $end] = $this->insightsRange($request);
        $location = $this->pickLocation($request);
        $integration = $this->connectedIntegration();

        $labels = [
            'impressions' => 'Total views', 'CALL_CLICKS' => 'Calls',
            'BUSINESS_CONVERSATIONS' => 'Chat / messages', 'BUSINESS_BOOKINGS' => 'Bookings',
            'BUSINESS_DIRECTION_REQUESTS' => 'Directions', 'WEBSITE_CLICKS' => 'Website clicks',
        ];

        if (! $integration || ! $location?->google_location_id) {
            return $this->ok([
                'connected' => false,
                'metrics' => [], 'series' => [], 'labels' => $labels,
                'message' => 'Connect your Google Business Profile to see live performance insights.',
            ]);
        }

        $data = $provider->fetchInsights($integration, $location->google_location_id, $days, $start, $end);
        if ($data === null) {
            return $this->fail($provider->getLastError() ?? 'Could not load insights from Google.', [], 502);
        }

        $t = $data['totals'];
        $impressions = ($t['BUSINESS_IMPRESSIONS_DESKTOP_SEARCH'] ?? 0) + ($t['BUSINESS_IMPRESSIONS_MOBILE_SEARCH'] ?? 0)
            + ($t['BUSINESS_IMPRESSIONS_DESKTOP_MAPS'] ?? 0) + ($t['BUSINESS_IMPRESSIONS_MOBILE_MAPS'] ?? 0);

        return $this->ok([
            'connected' => true,
            'days' => $days,
            'range' => ($start && $end) ? ['start' => $start->toDateString(), 'end' => $end->toDateString()] : null,
            'location' => ['id' => $location->uuid, 'name' => $location->name],
            'metrics' => [
                'impressions' => $impressions,
                'CALL_CLICKS' => $t['CALL_CLICKS'] ?? 0,
                'BUSINESS_CONVERSATIONS' => $t['BUSINESS_CONVERSATIONS'] ?? 0,
                'BUSINESS_BOOKINGS' => $t['BUSINESS_BOOKINGS'] ?? 0,
                'BUSINESS_DIRECTION_REQUESTS' => $t['BUSINESS_DIRECTION_REQUESTS'] ?? 0,
                'WEBSITE_CLICKS' => $t['WEBSITE_CLICKS'] ?? 0,
            ],
            'series' => $data['series'],
            'labels' => $labels,
        ]);
    }

    /** CSV export of the daily insights series. */
    public function insightsCsv(Request $request, GoogleGbpProvider $provider): StreamedResponse
    {
        $days = max(1, min(540, (int) $request->query('days', 30)));
        [$start, $end] = $this->insightsRange($request);
        $location = $this->pickLocation($request);
        $integration = $this->connectedIntegration();
        $rows = ($integration && $location?->google_location_id)
            ? ($provider->fetchInsights($integration, $location->google_location_id, $days, $start, $end)['series'] ?? [])
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

    /**
     * Upload an image for a post or photo (drag & drop / browse). Stored on the
     * public disk and returned as an absolute URL so the frontend can preview it
     * and Google can later fetch the bytes when the content is published.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'],
        ]);

        $path = $request->file('file')->store('gbp/' . now()->format('Y/m'), 'public');

        return $this->ok([
            // Served via the app (/media/…) so it works without a storage symlink
            // and Google can fetch it as a post image source.
            'url' => url('media/' . $path),
            'path' => $path,
        ], 201);
    }

    /**
     * Auto-generate (or refresh) a public business website + GBP report from the
     * connected location's data. Returns the live, shareable URL. Section 5 of
     * the Eydia lead doc — the Grexa-style instant website.
     */
    public function generateSite(Request $request, \App\Modules\Gbp\Services\BusinessSiteGenerator $gen): JsonResponse
    {
        $location = $this->pickLocation($request);
        if (! $location) {
            return $this->fail('Connect your Google Business Profile and sync a location first, then generate your website.', [], 422);
        }

        $site = $gen->generate($request->user()->tenant_id, $location);

        return $this->ok(['site' => $this->siteArray($site), 'message' => 'Your website is live.']);
    }

    /** The tenant's current generated website, or null. */
    public function siteStatus(Request $request): JsonResponse
    {
        $site = BusinessSite::query()->latest('published_at')->first();

        return $this->ok(['site' => $site ? $this->siteArray($site) : null]);
    }

    private function siteArray(BusinessSite $s): array
    {
        return [
            'id' => $s->uuid,
            'slug' => $s->slug,
            'title' => $s->title,
            'status' => $s->status,
            'url' => \App\Modules\Gbp\Services\BusinessSiteGenerator::url($s),
            'published_at' => $s->published_at?->toIso8601String(),
        ];
    }

    /**
     * The editable Business Information profile (About / Contact / Location /
     * Hours / More attributes) plus the review-share card, for the primary (or
     * ?location=) connected location.
     */
    public function profile(Request $request, \App\Modules\Gbp\Services\GbpProfileService $profiles): JsonResponse
    {
        $location = $this->pickLocation($request);

        return $this->ok([
            'profile' => $profiles->toArray($location),
            'connected' => (bool) $this->connectedIntegration(),
            'attribute_groups' => \App\Modules\Gbp\Services\GbpProfileService::ATTRIBUTE_GROUPS,
        ]);
    }

    /**
     * Save Business Information edits. Always persists to Eydia (which powers the
     * generated site, reports and review sharing); when Google is connected and
     * the location is linked, also patches the live Google Business Profile.
     */
    public function updateProfile(Request $request, \App\Modules\Gbp\Services\GbpProfileService $profiles, GoogleGbpProvider $provider): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'chat_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'website' => ['sometimes', 'nullable', 'string', 'max:500'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'service_area' => ['sometimes', 'array'],
            'service_area.*' => ['string', 'max:255'],
            'labels' => ['sometimes', 'array'],
            'labels.*' => ['string', 'max:255'],
            'opening_date' => ['sometimes', 'nullable', 'string', 'max:20'],
            'review_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'hours' => ['sometimes', 'array'],
            'hours.*.day' => ['required_with:hours', 'string'],
            'hours.*.open' => ['nullable', 'string', 'max:5'],
            'hours.*.close' => ['nullable', 'string', 'max:5'],
            'hours.*.closed' => ['nullable', 'boolean'],
            'special_hours' => ['sometimes', 'array'],
            'attributes' => ['sometimes', 'array'],
        ]);

        $location = $this->pickLocation($request)
            ?? new GbpLocation(['tenant_id' => $request->user()->tenant_id, 'name' => $data['name'] ?? 'My Business']);
        if (! $location->exists) {
            $location->tenant_id = $request->user()->tenant_id;
        }

        $location = $profiles->apply($location, $data);

        // Best-effort live push to Google when the listing is connected & linked.
        $syncedToGoogle = false;
        $syncMessage = null;
        $integration = $this->connectedIntegration();
        if ($integration && $location->google_location_id) {
            $patch = $profiles->googlePatch($data);
            if ($patch['mask'] !== '') {
                $syncedToGoogle = $provider->updateLocation($integration, $location->google_location_id, $patch['body'], $patch['mask']);
                if (! $syncedToGoogle) {
                    $syncMessage = $provider->getLastError() ?? 'Saved in Eydia, but Google did not accept the update.';
                }
            }
        }

        return $this->ok([
            'profile' => $profiles->toArray($location),
            'synced_to_google' => $syncedToGoogle,
            'message' => $syncedToGoogle
                ? 'Business information saved and pushed to Google.'
                : ($syncMessage ?? 'Business information saved.'),
        ]);
    }

    /**
     * Rewrite the business "from the business" description, optimised for local
     * SEO, from the saved profile (+ optional target keywords). Returns the draft
     * for the user to review and save — it does not overwrite anything itself.
     */
    public function aiRewriteProfile(Request $request, \App\Modules\Gbp\Services\GbpAiService $ai): JsonResponse
    {
        $data = $request->validate([
            'location' => ['nullable', 'string'],
            'keywords' => ['sometimes', 'array'],
            'keywords.*' => ['string', 'max:120'],
        ]);

        $location = $this->pickLocation($request);
        if (! $location) {
            return $this->fail('Add your business information first, then let AI optimise it.', [], 422);
        }
        if ($blocked = $this->guardCredits($request, 'ai_caption')) {
            return $blocked;
        }

        $description = $ai->rewriteDescription($location, $data['keywords'] ?? []);
        if (! $description) {
            return $this->fail(
                $ai->lastError() ?? 'No AI provider is active. Go to Settings → AI Provider, add a key and activate a provider.',
                [], 422
            );
        }

        $this->credits->deduct($request->user()->tenant_id, $request->user()->id, 'ai_caption', 'AI SEO rewrite: business description');

        return $this->ok([
            'description' => $description,
            'source' => 'ai',
            'credits' => $this->credits->balance($request->user()->tenant_id),
        ]);
    }

    /**
     * AI auto-setup: analyse the connected business and fill the whole Business
     * Information profile the best way — SEO description, highlights, keyword
     * labels, service area and default hours — without overwriting anything the
     * user already filled in. Pushes the writable fields to Google when linked.
     */
    public function autoSetupProfile(Request $request, \App\Modules\Gbp\Services\GbpProfileService $profiles, \App\Modules\Gbp\Services\GbpAiService $ai, GoogleGbpProvider $provider): JsonResponse
    {
        $location = $this->pickLocation($request);
        if (! $location) {
            return $this->fail('Connect Google and Sync first so AI has a business to set up.', [], 422);
        }
        if ($blocked = $this->guardCredits($request, 'ai_caption')) {
            return $blocked;
        }

        $plan = $ai->autoProfile($location);
        if (! $plan) {
            return $this->fail(
                $ai->lastError() ?? 'No AI provider is active yet. Go to Settings → AI Provider, add your API key and activate a provider, then try again.',
                [], 422
            );
        }

        $changed = $profiles->applyAutoPlan($location, $plan);
        $this->credits->deduct($request->user()->tenant_id, $request->user()->id, 'ai_caption', 'AI auto-setup: business profile');

        // Remember we've done the one-time auto-setup so a later sync won't redo it.
        $location->forceFill(['meta' => array_merge($location->meta ?? [], ['auto_setup_done' => true])])->save();

        // Best-effort live push of the writable fields (description, hours) to Google.
        $syncedToGoogle = false;
        $integration = $this->connectedIntegration();
        if ($integration && $location->google_location_id && $changed !== []) {
            $patch = $profiles->googlePatch($changed);
            if ($patch['mask'] !== '') {
                $syncedToGoogle = $provider->updateLocation($integration, $location->google_location_id, $patch['body'], $patch['mask']);
            }
        }

        return $this->ok([
            'profile' => $profiles->toArray($location->fresh()),
            'changed' => array_keys($changed),
            'synced_to_google' => $syncedToGoogle,
            'credits' => $this->credits->balance($request->user()->tenant_id),
            'message' => $changed === []
                ? 'Your profile already has these details filled in — nothing to overwrite.'
                : ('AI set up your profile: ' . implode(', ', array_keys($changed)) . '.'
                    . ($syncedToGoogle ? ' Pushed to Google.' : '')),
        ]);
    }

    /**
     * Parse an optional custom insights date range (?start=YYYY-MM-DD&end=…).
     * Returns [Carbon|null, Carbon|null]; both null falls back to the rolling
     * "last N days". A single day is allowed (start == end).
     *
     * @return array{0:?\Illuminate\Support\Carbon, 1:?\Illuminate\Support\Carbon}
     */
    private function insightsRange(Request $request): array
    {
        $s = $request->query('start');
        $e = $request->query('end');
        if (! $s || ! $e) {
            return [null, null];
        }
        try {
            $start = \Illuminate\Support\Carbon::parse($s)->startOfDay();
            $end = \Illuminate\Support\Carbon::parse($e)->startOfDay();
        } catch (\Throwable) {
            return [null, null];
        }
        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        return [$start, $end];
    }

    private function pickLocation(Request $request): ?GbpLocation
    {
        if ($loc = $request->query('location')) {
            return GbpLocation::where('uuid', $loc)->first();
        }

        // Prefer a managed (visible) location; fall back to any if all are hidden.
        return GbpLocation::visible()->orderByDesc('review_count')->first()
            ?? GbpLocation::query()->first();
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
            'is_hidden' => (bool) $l->is_hidden,
            'last_synced_at' => $l->last_synced_at?->toIso8601String(),
        ];
    }

    /** Hide or un-hide a location so only the ones the tenant wants drive the dashboard. */
    public function setLocationHidden(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['hidden' => ['required', 'boolean']]);
        $location = GbpLocation::where('uuid', $uuid)->firstOrFail();
        $location->update(['is_hidden' => $data['hidden']]);

        return $this->ok(['message' => $data['hidden'] ? 'Location hidden.' : 'Location restored.']);
    }

    /**
     * Keep ONLY this location and permanently remove every other one. For an
     * agency Google account that manages many client listings this is the
     * one-click "show only my business" cleanup: the others are deleted AND
     * remembered as dismissed, so a later Sync does not pull them back in.
     */
    public function keepOnlyLocation(Request $request, string $uuid): JsonResponse
    {
        $keep = GbpLocation::where('uuid', $uuid)->firstOrFail();

        $others = GbpLocation::query()->where('id', '!=', $keep->id)->get();
        $this->dismissLocations($others->pluck('google_location_id')->all());
        GbpLocation::query()->where('id', '!=', $keep->id)->delete();
        $keep->update(['is_hidden' => false]);

        return $this->ok(['message' => 'Showing only ' . $keep->name . '. Other locations removed.']);
    }

    /**
     * Permanently remove a synced location and its reviews/posts/photos, and
     * remember it as dismissed so a re-sync does not re-add it. Use this to clear
     * out businesses the connected (agency) Google account manages but that do
     * not belong in this dashboard.
     */
    public function destroyLocation(Request $request, string $uuid): JsonResponse
    {
        $location = GbpLocation::where('uuid', $uuid)->firstOrFail();
        $name = $location->name;
        $this->dismissLocations([$location->google_location_id]);
        $location->delete();

        return $this->ok(['message' => $name . ' removed.']);
    }

    /**
     * Record Google location ids the tenant does not want in this dashboard on
     * the GBP integration's meta, so GbpSyncService skips re-creating them. The
     * dismissed list is cleared on Disconnect (the Integration row is deleted).
     */
    private function dismissLocations(array $googleIds): void
    {
        $googleIds = array_values(array_filter(array_unique($googleIds)));
        if ($googleIds === []) {
            return;
        }

        $integration = Integration::where('provider', Integration::GOOGLE_GBP)->first();
        if (! $integration) {
            return;
        }

        $meta = $integration->meta ?? [];
        $meta['dismissed_locations'] = array_values(array_unique(array_merge(
            $meta['dismissed_locations'] ?? [],
            $googleIds
        )));
        $integration->forceFill(['meta' => $meta])->save();
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
            'draft_reply' => $r->draft_reply,
            'sentiment' => $r->sentiment ?: $this->sentiment->classify($r->comment),
            'replied_at' => $r->replied_at?->toIso8601String(),
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
        ];
    }
}
