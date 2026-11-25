<?php

namespace App\Models;

use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A permanent GBP location slot on the tenant's plan. See the migration for the
 * ownership rules. A slot is "connected" (actively managed) or "disconnected"
 * (reserved for the same location to reconnect later — never re-assignable).
 */
class PlanLocationSlot extends Model
{
    use BelongsToTenant;

    public const STATUS_CONNECTED = 'connected';
    public const STATUS_DISCONNECTED = 'disconnected';

    protected $fillable = [
        'tenant_id', 'user_id', 'plan_id', 'slot_no',
        'gbp_location_id', 'location_key', 'location_name', 'status',
    ];

    protected $casts = [
        'slot_no' => 'integer',
        'gbp_location_id' => 'integer',
        'plan_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(GbpLocation::class, 'gbp_location_id');
    }
}
