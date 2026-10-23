<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A generated public business website + GBP report. Served (when published) at
 * /site/{slug}; the `data` snapshot holds everything the page renders.
 */
class BusinessSite extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'gbp_location_id', 'slug', 'title', 'status', 'data', 'published_at',
    ];

    protected $casts = [
        'data' => 'array',
        'published_at' => 'datetime',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(GbpLocation::class, 'gbp_location_id');
    }
}
