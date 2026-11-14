<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * A discount coupon applied at checkout ON TOP of the cycle discount.
 * Percent coupons can be capped with max_discount. Not tenant-scoped.
 */
class Coupon extends Model
{
    use HasUuid;

    protected $fillable = [
        'code', 'type', 'value', 'max_discount', 'min_amount',
        'max_uses', 'used_count', 'cycles', 'starts_at', 'expires_at', 'is_active',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'min_amount' => 'decimal:2',
        'max_uses' => 'integer',
        'used_count' => 'integer',
        'cycles' => 'array',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function setCodeAttribute(string $value): void
    {
        $this->attributes['code'] = strtoupper(trim($value));
    }

    /** Is the coupon usable right now (ignoring cart-specific checks)? */
    public function isLive(): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }
        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return false;
        }

        return true;
    }
}
