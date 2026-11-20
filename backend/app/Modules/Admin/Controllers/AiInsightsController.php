<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AiSetting;
use App\Models\AiUsageLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Super-admin AI spend insights: how much each provider (Gemini / OpenAI /
 * Anthropic) and each model has cost, with token counts and a daily trend.
 *
 * Figures are computed from our own per-call usage ledger (ai_usage_logs) priced
 * with config/ai_pricing.php — this is the only source that covers all three
 * providers uniformly (Google AI Studio has no public billing API). OpenAI and
 * Anthropic also publish official cost APIs that need a separate Admin key; those
 * can be wired in later for cross-checking.
 */
class AiInsightsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $range = (string) $request->query('range', '30d');
        $since = match ($range) {
            '7d' => now()->subDays(7),
            'mtd' => now()->startOfMonth(),
            'all' => null,
            default => now()->subDays(30),
        };

        $scoped = fn () => AiUsageLog::query()->when($since, fn ($q) => $q->where('created_at', '>=', $since));

        $byProvider = $scoped()
            ->selectRaw('provider, COUNT(*) as calls, COALESCE(SUM(input_tokens),0) as in_tok, COALESCE(SUM(output_tokens),0) as out_tok, COALESCE(SUM(images),0) as images, COALESCE(SUM(cost_micros),0) as cost')
            ->groupBy('provider')
            ->orderByDesc('cost')
            ->get()
            ->map(fn ($r) => $this->row($r))
            ->values();

        $byModel = $scoped()
            ->selectRaw('provider, model, COUNT(*) as calls, COALESCE(SUM(input_tokens),0) as in_tok, COALESCE(SUM(output_tokens),0) as out_tok, COALESCE(SUM(images),0) as images, COALESCE(SUM(cost_micros),0) as cost')
            ->groupBy('provider', 'model')
            ->orderByDesc('cost')
            ->get()
            ->map(fn ($r) => $this->row($r))
            ->values();

        // Daily trend (last 30 days) for a small chart.
        $daily = AiUsageLog::where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw('DATE(created_at) as d, COALESCE(SUM(cost_micros),0) as cost')
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->keyBy('d');

        $series = [];
        for ($i = 29; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $series[] = [
                'date' => $day,
                'cost_usd' => round(((int) ($daily[$day]->cost ?? 0)) / 1_000_000, 4),
            ];
        }

        $rate = (float) config('ai_pricing.usd_to_inr', 88);
        $sumMicros = fn ($q) => (int) $q->sum('cost_micros');

        $rangeCost = $sumMicros($scoped());
        $mtdCost = $sumMicros(AiUsageLog::where('created_at', '>=', now()->startOfMonth()));
        $allTimeCost = $sumMicros(AiUsageLog::query());
        $todayCost = $sumMicros(AiUsageLog::where('created_at', '>=', now()->startOfDay()));

        $settings = AiSetting::current();

        // All-time spend per provider, to compute remaining balance.
        $allTimeByProvider = AiUsageLog::selectRaw('provider, COALESCE(SUM(cost_micros),0) as cost')
            ->groupBy('provider')->pluck('cost', 'provider');
        $balances = (array) ($settings->meta['balances'] ?? []);

        $providers = [];
        foreach (AiSetting::PROVIDERS as $p) {
            $spentUsd = ((int) ($allTimeByProvider[$p] ?? 0)) / 1_000_000;
            $balance = isset($balances[$p]) && $balances[$p] !== null ? (float) $balances[$p] : null;
            $providers[$p] = [
                'has_key' => (bool) $settings->keyFor($p),
                'model' => $settings->modelFor($p),
                'active' => $settings->activeProvider() === $p,
                'balance_usd' => $balance,
                'balance_inr' => $balance !== null ? round($balance * $rate, 2) : null,
                'spent_all_time_usd' => round($spentUsd, 4),
                'remaining_usd' => $balance !== null ? round($balance - $spentUsd, 4) : null,
                'remaining_inr' => $balance !== null ? round(($balance - $spentUsd) * $rate, 2) : null,
            ];
        }

        return $this->ok([
            'range' => $range,
            'usd_to_inr' => $rate,
            'totals' => [
                'range' => $this->money($rangeCost, $rate),
                'today' => $this->money($todayCost, $rate),
                'month_to_date' => $this->money($mtdCost, $rate),
                'all_time' => $this->money($allTimeCost, $rate),
            ],
            'by_provider' => $byProvider,
            'by_model' => $byModel,
            'daily' => $series,
            'active_provider' => $settings->activeProvider(),
            'providers' => $providers,
            // Honest provenance so the UI can label the numbers correctly.
            'source' => 'internal_ledger',
            'note' => 'Estimated from this app\'s own per-call usage, priced with current list rates. Google AI Studio has no public billing API; OpenAI & Anthropic publish official cost APIs (separate Admin key) for cross-checking.',
        ]);
    }

    /** Shape one aggregate row with USD + INR cost. */
    private function row(object $r): array
    {
        $rate = (float) config('ai_pricing.usd_to_inr', 88);
        $cost = (int) $r->cost;

        return [
            'provider' => $r->provider,
            'model' => $r->model ?? null,
            'calls' => (int) $r->calls,
            'input_tokens' => (int) $r->in_tok,
            'output_tokens' => (int) $r->out_tok,
            'images' => (int) $r->images,
            'cost_usd' => round($cost / 1_000_000, 4),
            'cost_inr' => round(($cost / 1_000_000) * $rate, 2),
        ];
    }

    private function money(int $micros, float $rate): array
    {
        $usd = $micros / 1_000_000;

        return ['usd' => round($usd, 4), 'inr' => round($usd * $rate, 2)];
    }
}
