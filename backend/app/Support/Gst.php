<?php

namespace App\Support;

/**
 * GST maths for GST-INCLUSIVE prices (the displayed price already includes tax).
 * base = price / (1 + rate/100); tax = price - base.
 */
class Gst
{
    /** @return array{base: float, tax: float, total: float, rate: int} in rupees */
    public static function inclusive(float $price, int $rate): array
    {
        $total = round($price, 2);
        $base = round($total * 100 / (100 + max(0, $rate)), 2);

        return ['base' => $base, 'tax' => round($total - $base, 2), 'total' => $total, 'rate' => $rate];
    }

    /** @return array{base: int, tax: int, total: int, rate: int} in paise (for Razorpay) */
    public static function inclusivePaise(float $price, int $rate): array
    {
        $total = (int) round($price * 100);
        $base = (int) round($total * 100 / (100 + max(0, $rate)));

        return ['base' => $base, 'tax' => $total - $base, 'total' => $total, 'rate' => $rate];
    }
}
