<?php

namespace App\Modules\Billing\Services;

use App\Models\Coupon;

/**
 * Validates a coupon and computes its discount ON TOP of the already
 * cycle-discounted, GST-inclusive price (rupees). Never takes the total below ₹0.
 */
class CouponService
{
    /**
     * @return array{coupon:?Coupon, discount:float, final:float, error:?string}
     */
    public function evaluate(?string $code, float $price, string $cycle): array
    {
        $code = $code ? strtoupper(trim($code)) : '';
        if ($code === '') {
            return ['coupon' => null, 'discount' => 0.0, 'final' => $price, 'error' => null];
        }

        $coupon = Coupon::whereRaw('UPPER(code) = ?', [$code])->first();
        if (! $coupon || ! $coupon->isLive()) {
            return $this->fail($price, 'This coupon code is invalid or has expired.');
        }
        if (! empty($coupon->cycles) && ! in_array($cycle, $coupon->cycles, true)) {
            return $this->fail($price, 'This coupon does not apply to the selected plan duration.');
        }
        if ($coupon->min_amount && $price < (float) $coupon->min_amount) {
            return $this->fail($price, 'Order total is below this coupon’s minimum.');
        }

        $discount = $coupon->type === 'flat'
            ? (float) $coupon->value
            : $price * ((float) $coupon->value / 100);

        if ($coupon->type === 'percent' && $coupon->max_discount) {
            $discount = min($discount, (float) $coupon->max_discount);
        }
        $discount = round(max(0.0, min($discount, $price)), 2);

        return ['coupon' => $coupon, 'discount' => $discount, 'final' => round($price - $discount, 2), 'error' => null];
    }

    /** Mark a coupon used (call once a payment actually succeeds). */
    public function redeem(Coupon $coupon): void
    {
        $coupon->increment('used_count');
    }

    private function fail(float $price, string $msg): array
    {
        return ['coupon' => null, 'discount' => 0.0, 'final' => $price, 'error' => $msg];
    }
}
