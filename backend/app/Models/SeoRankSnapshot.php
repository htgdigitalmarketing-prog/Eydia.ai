<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single rank-check result for a tracked keyword. Keeps history so the
 * dashboard can chart movement over time.
 */
class SeoRankSnapshot extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'seo_keyword_id', 'rank', 'source', 'meta', 'checked_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'checked_at' => 'datetime',
    ];

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(SeoKeyword::class, 'seo_keyword_id');
    }
}
