<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Spatie\Permission\Traits\HasRoles;

/**
 * A user belongs to exactly one tenant, except super admins (tenant_id null,
 * is_super_admin true) who operate through the audited admin guard.
 *
 * NOTE: User intentionally does NOT use BelongsToTenant — auth resolution must
 * be able to find a user across tenants at login by (tenant + email). Tenant
 * isolation for users is enforced at the query/policy layer.
 */
class User extends Authenticatable implements JWTSubject
{
    use HasApiTokens, HasFactory, HasRoles, HasUuid, Notifiable, SoftDeletes;

    protected string $guard_name = 'api';

    protected $fillable = [
        'tenant_id', 'name', 'email', 'password', 'phone', 'address',
        'avatar_url', 'status', 'timezone',
        'email_verified_at', 'last_login_at',
        'google_id', 'google_refresh_token',
    ];

    protected $hidden = [
        'password', 'remember_token', 'google_refresh_token',
        'two_factor_secret', 'two_factor_recovery_codes',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'two_factor_confirmed_at' => 'datetime',
        'password' => 'hashed',
        'is_super_admin' => 'boolean',
    ];

    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin === true;
    }

    // --- JWTSubject ---
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [
            'tenant_id' => $this->tenant_id,
            'is_super_admin' => $this->is_super_admin,
        ];
    }

    // --- Relations ---
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function agent(): HasOne
    {
        return $this->hasOne(Agent::class);
    }
}
