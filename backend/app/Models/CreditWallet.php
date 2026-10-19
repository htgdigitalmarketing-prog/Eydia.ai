<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant's AI credit wallet. Holds the current balance and the per-period
 * monthly allowance (from the plan). Credits are usage units, not money.
 */
class CreditWallet extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = ['tenant_id', 'balance', 'monthly_allowance', 'reset_at'];

    protected $casts = [
        'balance' => 'integer',
        'monthly_allowance' => 'integer',
        'reset_at' => 'datetime',
    ];

    public function ledgers(): HasMany
    {
        return $this->hasMany(CreditLedger::class);
    }
}
