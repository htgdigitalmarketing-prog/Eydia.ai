<?php

namespace App\Support;

use App\Models\BillingSetting;

/**
 * The billing cycles a customer can choose at checkout. A plan stores a MONTHLY
 * price; longer cycles multiply it by the number of months and apply a discount.
 * The discount per cycle is BACKEND-MANAGED (BillingSetting) — the values in
 * CYCLES below are only the fallback defaults. One place defines months, the
 * default discount, and the Razorpay subscription period/interval/mandate length.
 */
class BillingCycle
{
    /** @var array<string, array{label:string, months:int, discount:float, period:string, interval:int, total_count:int}> */
    public const CYCLES = [
        'monthly' => ['label' => 'Monthly', 'months' => 1, 'discount' => 0.0, 'period' => 'monthly', 'interval' => 1, 'total_count' => 120],
        'quarterly' => ['label' => 'Quarterly', 'months' => 3, 'discount' => 0.10, 'period' => 'monthly', 'interval' => 3, 'total_count' => 40],
        'half_yearly' => ['label' => '6 Months', 'months' => 6, 'discount' => 0.15, 'period' => 'monthly', 'interval' => 6, 'total_count' => 20],
        'yearly' => ['label' => 'Yearly', 'months' => 12, 'discount' => 0.20, 'period' => 'yearly', 'interval' => 1, 'total_count' => 10],
    ];

    public static function valid(string $cycle): bool
    {
        return array_key_exists($cycle, self::CYCLES);
    }

    public static function normalize(?string $cycle): string
    {
        return ($cycle && self::valid($cycle)) ? $cycle : 'monthly';
    }

    /** @return array{label:string, months:int, discount:float, period:string, interval:int, total_count:int} */
    public static function config(string $cycle): array
    {
        return self::CYCLES[self::normalize($cycle)];
    }

    /** Default discount PERCENTS per cycle (seed + fallback for BillingSetting). */
    public static function defaultDiscounts(): array
    {
        $out = [];
        foreach (self::CYCLES as $key => $c) {
            $out[$key] = (int) round($c['discount'] * 100);
        }

        return $out;
    }

    /** @return array<int,string> the cycles the pricing page shows by default. */
    public static function defaultEnabledCycles(): array
    {
        return ['monthly', 'half_yearly', 'yearly'];
    }

    /** The current discount fraction (0..1) for a cycle, from backend settings. */
    public static function discount(string $cycle): float
    {
        return BillingSetting::current()->discountFraction(self::normalize($cycle));
    }

    /** The GST-inclusive price (rupees) for a plan's monthly price over a cycle. */
    public static function price(float $monthlyPrice, string $cycle): float
    {
        $c = self::config($cycle);
        $gross = $monthlyPrice * $c['months'];

        return round($gross * (1 - self::discount($cycle)), 2);
    }

    /** The undiscounted list price (rupees) for a cycle — the struck-through figure. */
    public static function listPrice(float $monthlyPrice, string $cycle): float
    {
        return round($monthlyPrice * self::config($cycle)['months'], 2);
    }

    /** Months to add to the subscription period for a cycle. */
    public static function months(string $cycle): int
    {
        return self::config($cycle)['months'];
    }

    /**
     * Per-cycle price cards for a plan's monthly price — list (struck) price,
     * discounted price, per-month figure and discount %, for every cycle the
     * admin has enabled. Powers the pricing page.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function displayFor(float $monthlyPrice): array
    {
        $out = [];
        foreach (BillingSetting::current()->cyclesToShow() as $cycle) {
            $cfg = self::config($cycle);
            $price = self::price($monthlyPrice, $cycle);
            $out[] = [
                'key' => $cycle,
                'label' => $cfg['label'],
                'months' => $cfg['months'],
                'list' => self::listPrice($monthlyPrice, $cycle),
                'price' => $price,
                'per_month' => $cfg['months'] > 0 ? round($price / $cfg['months'], 2) : $price,
                'discount_percent' => (int) round(self::discount($cycle) * 100),
            ];
        }

        return $out;
    }

    /** The launch-offer banner/countdown info for the pricing page. */
    public static function offerInfo(): array
    {
        $bs = BillingSetting::current();

        return [
            'active' => $bs->offerLive(),
            'label' => $bs->offer_label,
            'ends_at' => $bs->offer_ends_at?->toIso8601String(),
        ];
    }
}
