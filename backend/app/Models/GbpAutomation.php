<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Per-tenant Google Business Profile automation settings — the ON/OFF switches
 * for the AI features plus the content cadence, and the slug for the public QR
 * review-assist page.
 */
class GbpAutomation extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    public const TOGGLES = ['auto_review_replies', 'auto_posts', 'auto_photos', 'qr_review_assist'];

    protected $fillable = [
        'tenant_id', 'gbp_location_id', 'slug',
        'auto_review_replies', 'auto_posts', 'auto_photos', 'qr_review_assist',
        'reply_tone', 'post_frequency_days', 'post_queue_target', 'last_run_at', 'meta',
    ];

    protected $casts = [
        'auto_review_replies' => 'boolean',
        'auto_posts' => 'boolean',
        'auto_photos' => 'boolean',
        'qr_review_assist' => 'boolean',
        'post_frequency_days' => 'integer',
        'post_queue_target' => 'integer',
        'last_run_at' => 'datetime',
        'meta' => 'array',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(GbpLocation::class, 'gbp_location_id');
    }

    /** The tenant's automation row, created with defaults on first access. */
    public static function forTenant(int $tenantId): self
    {
        return static::firstOrCreate(['tenant_id' => $tenantId]);
    }

    /** Give the public review-assist page a stable, shareable slug. */
    public function ensureSlug(?string $businessName = null): string
    {
        if (! $this->slug) {
            $base = Str::slug($businessName ?: 'review') ?: 'review';
            $this->slug = $base . '-' . Str::lower(Str::random(6));
            $this->saveQuietly();
        }

        return $this->slug;
    }
}
