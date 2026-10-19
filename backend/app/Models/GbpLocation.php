<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Google Business Profile location connected to the tenant. Holds cached
 * rating/aggregate data synced from the Google Business Profile API.
 */
class GbpLocation extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'google_location_id', 'name', 'address', 'phone', 'website',
        'category', 'rating', 'review_count', 'is_verified', 'last_synced_at', 'meta',
    ];

    protected $casts = [
        'rating' => 'decimal:2',
        'review_count' => 'integer',
        'is_verified' => 'boolean',
        'last_synced_at' => 'datetime',
        'meta' => 'array',
    ];

    public function reviews(): HasMany
    {
        return $this->hasMany(GbpReview::class, 'gbp_location_id');
    }
}
