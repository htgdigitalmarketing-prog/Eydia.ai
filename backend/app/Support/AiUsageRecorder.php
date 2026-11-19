<?php

namespace App\Support;

use App\Models\AiUsageLog;
use Illuminate\Support\Facades\Log;

/**
 * Writes one {@see AiUsageLog} row per AI call with the USD cost computed from
 * config/ai_pricing.php. Every method is best-effort: accounting must never
 * break or slow an AI feature, so all work is wrapped in try/catch.
 */
class AiUsageRecorder
{
    public function recordText(string $provider, string $model, int $inputTokens, int $outputTokens, string $operation = 'text', ?int $tenantId = null): void
    {
        try {
            [$inRate, $outRate] = $this->textRate($model);
            $costUsd = ($inputTokens / 1_000_000) * $inRate + ($outputTokens / 1_000_000) * $outRate;

            AiUsageLog::create([
                'tenant_id' => $tenantId,
                'provider' => $provider,
                'model' => $model,
                'operation' => $operation,
                'input_tokens' => max(0, $inputTokens),
                'output_tokens' => max(0, $outputTokens),
                'images' => 0,
                'cost_micros' => (int) round($costUsd * 1_000_000),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AiUsageRecorder.recordText failed: ' . $e->getMessage());
        }
    }

    public function recordImage(string $provider, string $model, int $images = 1, ?int $tenantId = null): void
    {
        try {
            $rate = $this->imageRate($model);
            $costUsd = $images * $rate;

            AiUsageLog::create([
                'tenant_id' => $tenantId,
                'provider' => $provider,
                'model' => $model,
                'operation' => 'image',
                'input_tokens' => 0,
                'output_tokens' => 0,
                'images' => max(0, $images),
                'cost_micros' => (int) round($costUsd * 1_000_000),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AiUsageRecorder.recordImage failed: ' . $e->getMessage());
        }
    }

    /** @return array{0:float,1:float} [inputPerMTok, outputPerMTok] */
    private function textRate(string $model): array
    {
        $table = (array) config('ai_pricing.text', []);
        if (isset($table[$model])) {
            return $table[$model];
        }

        $prefixes = (array) config('ai_pricing.text_prefixes', []);
        $best = null;
        $bestLen = -1;
        foreach ($prefixes as $prefix => $rate) {
            if (str_starts_with($model, $prefix) && strlen($prefix) > $bestLen) {
                $best = $rate;
                $bestLen = strlen($prefix);
            }
        }

        return $best ?? (array) config('ai_pricing.default_text', [0.5, 1.5]);
    }

    private function imageRate(string $model): float
    {
        $table = (array) config('ai_pricing.image', []);
        if (isset($table[$model])) {
            return (float) $table[$model];
        }

        $prefixes = (array) config('ai_pricing.image_prefixes', []);
        $best = null;
        $bestLen = -1;
        foreach ($prefixes as $prefix => $rate) {
            if (str_starts_with($model, $prefix) && strlen($prefix) > $bestLen) {
                $best = (float) $rate;
                $bestLen = strlen($prefix);
            }
        }

        return $best ?? (float) config('ai_pricing.default_image', 0.04);
    }
}
