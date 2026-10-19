<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Balance is stored in minor units (paise/cents) as integers — never floats.
 * Mutate only through WalletService inside a DB transaction, always writing a
 * paired WalletTransaction row.
 */
class Wallet extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'currency', 'balance_minor', 'reserved_minor',
        'auto_recharge', 'auto_recharge_threshold_minor', 'auto_recharge_amount_minor',
    ];

    protected $casts = [
        'balance_minor' => 'integer',
        'reserved_minor' => 'integer',
        'auto_recharge' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
