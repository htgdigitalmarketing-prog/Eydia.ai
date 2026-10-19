<?php

namespace App\Modules\Ads\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdReport;
use App\Models\Integration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ads reporting dashboard (ported from ReviewFlow). Lists ad-performance
 * reports with roll-up totals and the tenant's Google/Meta connection status,
 * and accepts manual report entry. Tenant-scoped via AdReport's BelongsToTenant.
 * Gated by plan feature 'ads'.
 */
class AdController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AdReport::query()->with('location:id,uuid,name')->orderByDesc('period_end');
        if ($network = $request->query('network')) {
            $query->where('network', strtoupper($network));
        }
        $reports = $query->get();

        return $this->ok([
            'reports' => $reports->map(fn (AdReport $r) => $this->reportArray($r))->all(),
            'networks' => AdReport::NETWORKS,
            'totals' => [
                'spend' => (float) $reports->sum('spend'),
                'clicks' => (int) $reports->sum('clicks'),
                'impressions' => (int) $reports->sum('impressions'),
                'conversions' => (int) $reports->sum('conversions'),
                'leads' => (int) $reports->sum('leads'),
            ],
            'has_google' => Integration::where('provider', Integration::GOOGLE_GBP)->whereNotNull('access_token')->exists(),
            'has_meta' => Integration::where('provider', Integration::META_LEADS)->whereNotNull('access_token')->exists(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'network' => ['required', Rule::in(AdReport::NETWORKS)],
            'campaign' => ['required', 'string', 'max:255'],
            'spend' => ['nullable', 'numeric', 'min:0'],
            'clicks' => ['nullable', 'integer', 'min:0'],
            'impressions' => ['nullable', 'integer', 'min:0'],
            'conversions' => ['nullable', 'integer', 'min:0'],
            'leads' => ['nullable', 'integer', 'min:0'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'location_id' => ['nullable', 'string', 'exists:gbp_locations,uuid'],
        ]);

        $report = AdReport::create([
            'network' => $data['network'],
            'campaign' => $data['campaign'],
            'spend' => $data['spend'] ?? 0,
            'clicks' => $data['clicks'] ?? 0,
            'impressions' => $data['impressions'] ?? 0,
            'conversions' => $data['conversions'] ?? 0,
            'leads' => $data['leads'] ?? 0,
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'gbp_location_id' => isset($data['location_id'])
                ? \App\Models\GbpLocation::where('uuid', $data['location_id'])->value('id')
                : null,
        ]);

        return $this->ok(['report' => $this->reportArray($report->fresh('location'))], 201);
    }

    public function destroy(string $uuid): JsonResponse
    {
        AdReport::where('uuid', $uuid)->firstOrFail()->delete();

        return $this->ok(['message' => 'Report deleted.']);
    }

    private function reportArray(AdReport $r): array
    {
        return [
            'id' => $r->uuid,
            'network' => $r->network,
            'campaign' => $r->campaign,
            'spend' => (float) $r->spend,
            'clicks' => $r->clicks,
            'impressions' => $r->impressions,
            'conversions' => $r->conversions,
            'leads' => $r->leads,
            'ctr' => $r->impressions ? round($r->clicks / $r->impressions * 100, 2) : 0,
            'cpl' => $r->leads ? round($r->spend / $r->leads, 2) : null,
            'location' => $r->location ? ['id' => $r->location->uuid, 'name' => $r->location->name] : null,
            'period_start' => $r->period_start?->toDateString(),
            'period_end' => $r->period_end?->toDateString(),
        ];
    }
}
