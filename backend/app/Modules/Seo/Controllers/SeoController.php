<?php

namespace App\Modules\Seo\Controllers;

use App\Http\Controllers\Controller;
use App\Models\GbpLocation;
use App\Models\GbpReview;
use App\Models\SeoCompetitor;
use App\Models\SeoKeyword;
use App\Models\SeoRankSnapshot;
use App\Modules\Billing\Services\CreditService;
use App\Modules\Seo\Services\SeoAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Local-SEO toolkit: keyword tracking + rank checking + competitor analysis
 * (ported from ReviewFlow). Tenant-scoped automatically via the models'
 * BelongsToTenant. AI features reuse the shared AiReplyService and degrade to
 * honest guidance when no AI provider is configured. Gated by plan feature 'seo'.
 */
class SeoController extends Controller
{
    public function __construct(private SeoAiService $ai, private CreditService $credits)
    {
    }

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

    public function overview(Request $request): JsonResponse
    {
        return $this->ok([
            'keywords' => SeoKeyword::count(),
            'competitors' => SeoCompetitor::count(),
            'checks_this_month' => SeoRankSnapshot::where('checked_at', '>=', now()->startOfMonth())->count(),
            'ai_configured' => $this->ai->configured(),
            'locations' => GbpLocation::get(['uuid', 'name'])
                ->map(fn ($l) => ['id' => $l->uuid, 'name' => $l->name])->all(),
        ]);
    }

    // ---- Keyword tracking -------------------------------------------------

    public function keywords(Request $request): JsonResponse
    {
        $keywords = SeoKeyword::with('location:id,uuid,name')
            ->withCount('snapshots')
            ->latest()
            ->get();

        return $this->ok([
            'keywords' => $keywords->map(fn (SeoKeyword $k) => $this->keywordArray($k))->all(),
        ]);
    }

    public function storeKeyword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:255'],
            'location_id' => ['nullable', 'string', 'exists:gbp_locations,uuid'],
        ]);

        $keyword = SeoKeyword::create([
            'keyword' => $data['keyword'],
            'city' => $data['city'] ?? null,
            'gbp_location_id' => $this->resolveLocationId($data['location_id'] ?? null),
        ]);

        return $this->ok(['keyword' => $this->keywordArray($keyword->fresh('location')->loadCount('snapshots'))], 201);
    }

    public function deleteKeyword(string $uuid): JsonResponse
    {
        SeoKeyword::where('uuid', $uuid)->firstOrFail()->delete();

        return $this->ok(['message' => 'Keyword removed.']);
    }

    /** Run a rank check for a tracked keyword, append a snapshot, update latest_rank. */
    public function checkKeyword(Request $request, string $uuid): JsonResponse
    {
        if ($blocked = $this->guardCredits($request, 'ai_rank_check')) {
            return $blocked;
        }
        $keyword = SeoKeyword::with('location')->where('uuid', $uuid)->firstOrFail();

        $business = $keyword->location?->name ?: 'your business';
        $where = $keyword->city ?: ($keyword->location?->address ?: '');

        $result = $this->estimateRank($business, $keyword->keyword, $where);

        $keyword->snapshots()->create([
            'tenant_id' => $keyword->tenant_id,
            'rank' => $result['rank'],
            'source' => $result['source'],
            'meta' => ['summary' => $result['summary'] ?? null, 'tips' => $result['tips'] ?? []],
            'checked_at' => now(),
        ]);
        $keyword->update(['latest_rank' => $result['rank'], 'last_checked_at' => now()]);
        $this->credits->deduct($request->user()->tenant_id, $request->user()->id, 'ai_rank_check', 'Rank check: ' . $keyword->keyword);

        return $this->ok([
            'keyword' => $this->keywordArray($keyword->fresh('location')->loadCount('snapshots')),
            'result' => $result,
            'credits' => $this->credits->balance($request->user()->tenant_id),
        ]);
    }

    /** Rank-check history for one keyword (for charting). */
    public function history(string $uuid): JsonResponse
    {
        $keyword = SeoKeyword::where('uuid', $uuid)->firstOrFail();
        $snapshots = $keyword->snapshots()->orderBy('checked_at')->get();

        return $this->ok([
            'snapshots' => $snapshots->map(fn (SeoRankSnapshot $s) => [
                'rank' => $s->rank,
                'source' => $s->source,
                'checked_at' => $s->checked_at?->toIso8601String(),
                'summary' => $s->meta['summary'] ?? null,
            ])->all(),
        ]);
    }

    /** AI keyword-idea generator (grouped). Honest fallback when AI is off. */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
        ]);

        if ($blocked = $this->guardCredits($request, 'ai_keyword_gen')) {
            return $blocked;
        }

        $system = 'You are a local-SEO keyword strategist. Return ONLY JSON.';
        $user = "Business: {$data['business']}; City: {$data['city']}; Industry: "
            . ($data['industry'] ?? 'general') . ".\n"
            . 'Return {"groups":[{"theme":"...","keywords":["..."]}]} with 4 themed groups of 5 local keywords each.';

        $parsed = $this->ai->json($system, $user, 500);
        if (is_array($parsed) && ! empty($parsed['groups'])) {
            $this->credits->deduct($request->user()->tenant_id, $request->user()->id, 'ai_keyword_gen', 'SEO keyword generation');

            return $this->ok(['groups' => $parsed['groups'], 'source' => 'ai', 'credits' => $this->credits->balance($request->user()->tenant_id)]);
        }

        // Deterministic fallback: still useful seed keywords, clearly labelled.
        $svc = $data['industry'] ?: $data['business'];
        $city = $data['city'];

        return $this->ok([
            'source' => 'fallback',
            'groups' => [
                ['theme' => 'Core', 'keywords' => ["{$svc} in {$city}", "best {$svc} {$city}", "{$svc} near me", "top {$svc} {$city}", "affordable {$svc} {$city}"]],
                ['theme' => 'Intent', 'keywords' => ["book {$svc} {$city}", "{$svc} appointment {$city}", "{$svc} price {$city}", "{$svc} quote {$city}", "{$svc} open now {$city}"]],
            ],
        ]);
    }

    // ---- Competitor analysis ----------------------------------------------

    public function competitors(Request $request): JsonResponse
    {
        $competitors = SeoCompetitor::with('location:id,uuid,name')->latest()->get();

        return $this->ok([
            'competitors' => $competitors->map(fn (SeoCompetitor $c) => $this->competitorArray($c))->all(),
        ]);
    }

    public function storeCompetitor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'location_id' => ['nullable', 'string', 'exists:gbp_locations,uuid'],
        ]);

        $competitor = SeoCompetitor::create([
            'name' => $data['name'],
            'city' => $data['city'] ?? null,
            'gbp_location_id' => $this->resolveLocationId($data['location_id'] ?? null),
        ]);

        return $this->ok(['competitor' => $this->competitorArray($competitor->fresh('location'))], 201);
    }

    public function deleteCompetitor(string $uuid): JsonResponse
    {
        SeoCompetitor::where('uuid', $uuid)->firstOrFail()->delete();

        return $this->ok(['message' => 'Competitor removed.']);
    }

    /** Run an AI comparison of the tenant's reviews vs the named competitor. */
    public function analyze(Request $request, string $uuid): JsonResponse
    {
        if ($blocked = $this->guardCredits($request, 'ai_competitor')) {
            return $blocked;
        }
        $competitor = SeoCompetitor::with('location')->where('uuid', $uuid)->firstOrFail();

        // Our own review stats give the model a factual anchor.
        $reviews = GbpReview::when(
            $competitor->gbp_location_id,
            fn ($q) => $q->where('gbp_location_id', $competitor->gbp_location_id)
        )->get(['rating']);
        $total = $reviews->count();
        $avg = $total ? round($reviews->avg('rating'), 1) : 0;
        $me = $competitor->location?->name ?: 'our business';
        $city = $competitor->city ?: '';

        $system = 'You are a local-SEO competitive analyst. Return ONLY JSON.';
        $user = "Compare {$me} ({$avg}/5 from {$total} reviews) vs {$competitor->name} in {$city}.\n"
            . 'Return {"summary":"2-3 sentences","strengths":["..."],"gaps":["..."],"actions":["4 steps"]}. One line each.';

        $parsed = $this->ai->json($system, $user, 400);
        $result = is_array($parsed) && isset($parsed['summary'])
            ? array_merge(['strengths' => [], 'gaps' => [], 'actions' => []], $parsed, ['source' => 'ai'])
            : [
                'source' => 'fallback',
                'summary' => "Comparing {$me} against {$competitor->name}. Configure an AI provider for a full analysis.",
                'strengths' => ["Your {$avg}/5 rating from {$total} reviews is a key asset"],
                'gaps' => ['Exact competitor metrics need Google API access'],
                'actions' => ['Ask happy customers for reviews', 'Reply to every review', 'Post weekly on Google', 'Keep business info accurate'],
            ];

        $competitor->update([
            'analysis' => $result['summary'],
            'last_analyzed_at' => now(),
            'meta' => $result,
        ]);

        $result['my_stats'] = ['avg' => $avg, 'total' => $total, 'name' => $me];

        if (($result['source'] ?? null) === 'ai') {
            $this->credits->deduct($request->user()->tenant_id, $request->user()->id, 'ai_competitor', 'Competitor analysis: ' . $competitor->name);
        }

        return $this->ok([
            'competitor' => $this->competitorArray($competitor->fresh('location')),
            'result' => $result,
            'credits' => $this->credits->balance($request->user()->tenant_id),
        ]);
    }

    // ---- helpers ----------------------------------------------------------

    /** AI rank estimate with an honest fallback. Never fabricates a numeric rank. */
    private function estimateRank(string $business, string $keyword, string $where): array
    {
        $system = 'You estimate Google local-pack rank. Return ONLY JSON.';
        $user = "Estimate local rank for \"{$business}\" in \"{$where}\" for keyword \"{$keyword}\".\n"
            . 'Return {"estimated_rank":"Top 3|4-10|Not in top 10","summary":"2-3 sentences","tips":["4 actions"]}';

        $parsed = $this->ai->json($system, $user, 300);
        if (is_array($parsed) && isset($parsed['summary'])) {
            return [
                'source' => 'ai',
                'rank' => $parsed['estimated_rank'] ?? 'Unknown',
                'summary' => $parsed['summary'],
                'tips' => $parsed['tips'] ?? [],
            ];
        }

        return [
            'source' => 'fallback',
            'rank' => null,
            'summary' => 'Configure an AI provider (or a Places API key) for a real ranking check.',
            'tips' => ['Keep your Business Profile complete', 'Collect and reply to reviews', 'Post weekly on Google', 'Use the keyword in your description'],
        ];
    }

    private function resolveLocationId(?string $uuid): ?int
    {
        if (! $uuid) {
            return null;
        }

        return GbpLocation::where('uuid', $uuid)->value('id');
    }

    private function keywordArray(SeoKeyword $k): array
    {
        return [
            'id' => $k->uuid,
            'keyword' => $k->keyword,
            'city' => $k->city,
            'location' => $k->location ? ['id' => $k->location->uuid, 'name' => $k->location->name] : null,
            'latest_rank' => $k->latest_rank,
            'checks' => $k->snapshots_count ?? $k->snapshots()->count(),
            'last_checked_at' => $k->last_checked_at?->toIso8601String(),
            'created_at' => $k->created_at?->toIso8601String(),
        ];
    }

    private function competitorArray(SeoCompetitor $c): array
    {
        return [
            'id' => $c->uuid,
            'name' => $c->name,
            'city' => $c->city,
            'location' => $c->location ? ['id' => $c->location->uuid, 'name' => $c->location->name] : null,
            'analysis' => $c->analysis,
            'last_analyzed_at' => $c->last_analyzed_at?->toIso8601String(),
        ];
    }
}
