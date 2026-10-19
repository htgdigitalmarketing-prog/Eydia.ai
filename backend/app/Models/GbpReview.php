<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single Google review for a connected GBP location. Replies are pushed back
 * to Google via the Business Profile API; reply text is cached here.
 */
class GbpReview extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'gbp_location_id', 'google_review_id', 'author_name',
        'author_photo', 'rating', 'comment', 'reply', 'replied_at',
        'reviewed_at', 'meta',
    ];

    protected $casts = [
        'rating' => 'integer',
        'replied_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(GbpLocation::class, 'gbp_location_id');
    }
}
