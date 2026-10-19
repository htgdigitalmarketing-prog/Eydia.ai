<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-tenant connection to a third-party provider (Google Business Profile,
 * Google Lead Forms, Meta Lead Ads). Holds the OAuth tokens — encrypted at
 * rest — that the sync services use to call the provider's API on the tenant's
 * behalf. One row per (tenant, provider).
 */
class Integration extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    public const GOOGLE_GBP = 'GOOGLE_GBP';
    public const GOOGLE_LEADS = 'GOOGLE_LEADS';
    public const META_LEADS = 'META_LEADS';

    protected $fillable = [
        'tenant_id', 'provider', 'status', 'access_token', 'refresh_token',
        'expires_at', 'external_account_id', 'external_account_name',
        'last_synced_at', 'meta',
    ];

    protected $casts = [
        // Tokens are encrypted at rest and never exposed to the frontend.
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'meta' => 'array',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected' && ! empty($this->access_token);
    }
}
