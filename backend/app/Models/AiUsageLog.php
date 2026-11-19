<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per AI provider call — model, tokens and the USD cost we computed for
 * it. Read by the super-admin AI insights dashboard. Not tenant-scoped (platform
 * spend view); tenant_id is captured when known.
 */
class AiUsageLog extends Model
{
    public const UPDATED_AT = null; // only created_at

    protected $fillable = [
        'tenant_id', 'provider', 'model', 'operation',
        'input_tokens', 'output_tokens', 'images', 'cost_micros',
    ];

    protected $casts = [
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'images' => 'integer',
        'cost_micros' => 'integer',
    ];

    /** Cost in whole US dollars (float). */
    public function costUsd(): float
    {
        return $this->cost_micros / 1_000_000;
    }
}
