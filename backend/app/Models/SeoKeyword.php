<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A keyword a tenant tracks for local-search rank, optionally tied to a GBP
 * location. Each rank check appends a SeoRankSnapshot and updates latest_rank.
 */
class SeoKeyword extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'gbp_location_id', 'keyword', 'city', 'latest_rank', 'last_checked_at', 'meta',
    ];

    protected $casts = [
        'last_checked_at' => 'datetime',
        'meta' => 'array',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(GbpLocation::class, 'gbp_location_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(SeoRankSnapshot::class, 'seo_keyword_id');
    }
}
