<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Gst;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A buyable AI-credit pack (platform-wide, not tenant-scoped). Price is
 * GST-inclusive.
 */
class CreditPack extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = ['name', 'credits', 'price', 'gst_rate', 'sort_order', 'is_active'];

    protected $casts = [
        'credits' => 'integer',
        'price' => 'decimal:2',
        'gst_rate' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /** @return array{base: float, tax: float, total: float, rate: int} */
    public function gstBreakdown(): array
    {
        return Gst::inclusive((float) $this->price, (int) $this->gst_rate);
    }
}
