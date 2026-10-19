<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An ad-campaign performance report for a period (Google or Meta). Tenant-owned.
 */
class AdReport extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    public const NETWORKS = ['GOOGLE', 'META'];

    protected $fillable = [
        'tenant_id', 'gbp_location_id', 'network', 'campaign', 'spend', 'clicks',
        'impressions', 'conversions', 'leads', 'period_start', 'period_end', 'meta',
    ];

    protected $casts = [
        'spend' => 'decimal:2',
        'clicks' => 'integer',
        'impressions' => 'integer',
        'conversions' => 'integer',
        'leads' => 'integer',
        'period_start' => 'date',
        'period_end' => 'date',
        'meta' => 'array',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(GbpLocation::class, 'gbp_location_id');
    }
}
