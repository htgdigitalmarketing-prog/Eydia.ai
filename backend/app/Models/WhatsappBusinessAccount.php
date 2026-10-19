<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TracksBlame;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WhatsappBusinessAccount extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, SoftDeletes, TracksBlame;

    protected $fillable = [
        'tenant_id', 'waba_id', 'business_portfolio_id', 'name', 'currency',
        'timezone_id', 'access_token', 'token_expires_at',
        'message_template_namespace', 'status', 'connected_at', 'meta',
    ];

    protected $casts = [
        // Meta System User token encrypted at rest; never exposed to the frontend.
        'access_token' => 'encrypted',
        'meta' => 'array',
        'token_expires_at' => 'datetime',
        'connected_at' => 'datetime',
    ];

    protected $hidden = ['access_token'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function phoneNumbers(): HasMany
    {
        return $this->hasMany(WhatsappPhoneNumber::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(Template::class);
    }
}
