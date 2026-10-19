<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'webhook_event_id', 'level', 'action', 'message', 'context',
    ];

    protected $casts = [
        'context' => 'array',
    ];
}
