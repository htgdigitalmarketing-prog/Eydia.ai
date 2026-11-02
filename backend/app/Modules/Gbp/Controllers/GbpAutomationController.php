<?php

namespace App\Modules\Gbp\Controllers;

use App\Http\Controllers\Controller;
use App\Models\GbpAutomation;
use App\Models\GbpLocation;
use App\Modules\Gbp\Services\GbpAutomationRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Automation control centre for a tenant's Google Business Profile: the
 * ON/OFF switches for each AI feature, the content cadence, and the public QR
 * review-assist link. One settings row per tenant.
 */
class GbpAutomationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $automation = GbpAutomation::forTenant($request->user()->tenant_id);
        $location = $this->primaryLocation($automation);

        return $this->ok($this->payload($automation, $location));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'auto_review_replies' => ['sometimes', 'boolean'],
            'auto_posts' => ['sometimes', 'boolean'],
            'auto_photos' => ['sometimes', 'boolean'],
            'qr_review_assist' => ['sometimes', 'boolean'],
            'reply_tone' => ['sometimes', 'string', 'max:120'],
            'post_frequency_days' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'post_queue_target' => ['sometimes', 'integer', 'min:1', 'max:12'],
        ]);

        $automation = GbpAutomation::forTenant($request->user()->tenant_id);
        $automation->fill($data)->save();
        $location = $this->primaryLocation($automation);

        return $this->ok($this->payload($automation, $location) + ['message' => 'Automation settings saved.']);
    }

    /**
     * Run this tenant's enabled automations right now (the "Run now" button),
     * instead of waiting for the hourly scheduler. Lets the user see AI replies
     * and scheduled posts/photos appear immediately, and surfaces a clear message
     * when no AI provider is configured yet.
     */
    public function runNow(Request $request, GbpAutomationRunner $runner): JsonResponse
    {
        $automation = GbpAutomation::forTenant($request->user()->tenant_id);

        if (! $automation->auto_review_replies && ! $automation->auto_posts && ! $automation->auto_photos) {
            return $this->fail('Turn on at least one automation first.', [], 422);
        }

        $result = $runner->run($automation);

        if (($result['skipped'] ?? null) === 'no_ai_provider') {
            return $this->fail('No AI provider is active yet. Go to Settings → AI Provider, add your API key and activate a provider, then try again.', [], 422);
        }

        $parts = [];
        if ($result['replies'] > 0) {
            $parts[] = $result['replies'] . ' review ' . ($result['replies'] === 1 ? 'reply' : 'replies') . ' drafted';
        }
        if ($result['posts'] > 0) {
            $parts[] = $result['posts'] . ' ' . ($result['posts'] === 1 ? 'post' : 'posts') . ' scheduled';
        }
        if ($result['images'] > 0) {
            $parts[] = $result['images'] . ' ' . ($result['images'] === 1 ? 'photo' : 'photos') . ' generated';
        }

        $location = $this->primaryLocation($automation);

        return $this->ok($this->payload($automation, $location) + [
            'ran' => $result,
            'message' => $parts !== []
                ? 'Done: ' . implode(', ', $parts) . '. See them in Posts & Photos / Reviews.'
                : 'Nothing to do right now — the post queue is full and all reviews are already replied to.',
        ]);
    }

    /** Resolve (and remember) the location these automations act on. */
    private function primaryLocation(GbpAutomation $automation): ?GbpLocation
    {
        $location = $automation->location
            ?: GbpLocation::visible()->orderByDesc('review_count')->first();

        if ($location && $automation->gbp_location_id !== $location->id) {
            $automation->forceFill(['gbp_location_id' => $location->id])->saveQuietly();
        }

        return $location;
    }

    private function payload(GbpAutomation $automation, ?GbpLocation $location): array
    {
        $reviewUrl = $location?->meta['review_url'] ?? null;
        $assistUrl = null;
        if ($location && $reviewUrl) {
            $slug = $automation->ensureSlug($location->name);
            $assistUrl = rtrim((string) config('app.url'), '/') . '/review/' . $slug;
        }

        return [
            'automation' => [
                'auto_review_replies' => $automation->auto_review_replies,
                'auto_posts' => $automation->auto_posts,
                'auto_photos' => $automation->auto_photos,
                'qr_review_assist' => $automation->qr_review_assist,
                'reply_tone' => $automation->reply_tone,
                'post_frequency_days' => $automation->post_frequency_days,
                'post_queue_target' => $automation->post_queue_target,
                'last_run_at' => $automation->last_run_at?->toIso8601String(),
            ],
            'business' => $location?->name,
            'has_location' => (bool) $location,
            'review_url' => $reviewUrl,
            // When QR review-assist is on and we have a review link, this is the
            // page the QR encodes (AI helps the customer write the review first).
            'assist_url' => $automation->qr_review_assist ? $assistUrl : null,
            'assist_url_preview' => $assistUrl, // always available to preview/test
        ];
    }
}
