<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Root of the multi-tenant tree. Not tenant-scoped itself.
 */
class Tenant extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'company_name', 'email', 'phone',
        'timezone', 'locale', 'status', 'trial_ends_at',
        'suspended_at', 'suspended_reason', 'settings',
    ];

    protected $casts = [
        'settings' => 'array',
        'trial_ends_at' => 'datetime',
        'suspended_at' => 'datetime',
    ];

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function whatsappBusinessAccounts(): HasMany
    {
        return $this->hasMany(WhatsappBusinessAccount::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }
}
