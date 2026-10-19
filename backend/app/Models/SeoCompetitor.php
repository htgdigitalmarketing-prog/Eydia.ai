<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A competitor a tenant watches, optionally tied to a GBP location. Holds the
 * last AI comparison so the dashboard can show it without re-running analysis.
 */
class SeoCompetitor extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'gbp_location_id', 'name', 'city', 'analysis', 'last_analyzed_at', 'meta',
    ];

    protected $casts = [
        'last_analyzed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(GbpLocation::class, 'gbp_location_id');
    }
}
