<?php

namespace App\Models;

use App\Support\BillingCycle;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform pricing settings. Singleton (one row) managed by the Super Admin:
 * per-cycle discount %, which cycles the pricing page shows, and the optional
 * limited-time launch offer (label + countdown). Not tenant-scoped.
 */
class BillingSetting extends Model
{
    protected $fillable = [
        'cycle_discounts', 'enabled_cycles', 'offer_label', 'offer_ends_at', 'offer_active',
    ];

    protected $casts = [
        'cycle_discounts' => 'array',
        'enabled_cycles' => 'array',
        'offer_ends_at' => 'datetime',
        'offer_active' => 'boolean',
    ];

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], [
            'cycle_discounts' => BillingCycle::defaultDiscounts(),
            'enabled_cycles' => BillingCycle::defaultEnabledCycles(),
            'offer_active' => false,
        ]);
    }

    /** Discount fraction (0..1) for a cycle, from settings (percent), else default. */
    public function discountFraction(string $cycle): float
    {
        $percent = ($this->cycle_discounts ?? [])[$cycle] ?? (BillingCycle::defaultDiscounts()[$cycle] ?? 0);

        return max(0.0, min(100.0, (float) $percent)) / 100;
    }

    /** @return array<int,string> cycles to display, filtered to valid ones. */
    public function cyclesToShow(): array
    {
        $cycles = $this->enabled_cycles ?: BillingCycle::defaultEnabledCycles();

        return array_values(array_filter($cycles, fn ($c) => BillingCycle::valid($c)));
    }

    /** Whether the launch offer is live right now. */
    public function offerLive(): bool
    {
        return (bool) $this->offer_active
            && (! $this->offer_ends_at || $this->offer_ends_at->isFuture());
    }
}
