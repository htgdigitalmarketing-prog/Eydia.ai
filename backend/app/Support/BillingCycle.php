<?php

namespace App\Support;

/**
 * The billing cycles a customer can choose at checkout. A plan stores a MONTHLY
 * price; longer cycles multiply it by the number of months and apply a discount,
 * so yearly is cheaper per month. One place defines months, discount, the
 * Razorpay subscription period/interval and how long the mandate runs.
 */
class BillingCycle
{
    /** @var array<string, array{label:string, months:int, discount:float, period:string, interval:int, total_count:int}> */
    public const CYCLES = [
        'monthly' => ['label' => 'Monthly', 'months' => 1, 'discount' => 0.0, 'period' => 'monthly', 'interval' => 1, 'total_count' => 120],
        'quarterly' => ['label' => 'Quarterly', 'months' => 3, 'discount' => 0.10, 'period' => 'monthly', 'interval' => 3, 'total_count' => 40],
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

    /** The GST-inclusive price (rupees) for a plan's monthly price over a cycle. */
    public static function price(float $monthlyPrice, string $cycle): float
    {
        $c = self::config($cycle);
        $gross = $monthlyPrice * $c['months'];

        return round($gross * (1 - $c['discount']), 2);
    }

    /** Months to add to the subscription period for a cycle. */
    public static function months(string $cycle): int
    {
        return self::config($cycle)['months'];
    }
}
