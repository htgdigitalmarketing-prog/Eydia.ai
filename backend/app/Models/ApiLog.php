<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Outbound/inbound API call log. request/response are masked before storage.
 */
class ApiLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'direction', 'service', 'method', 'endpoint',
        'status_code', 'request', 'response', 'duration_ms', 'error_code',
    ];

    protected $casts = [
        'request' => 'array',
        'response' => 'array',
    ];
}
