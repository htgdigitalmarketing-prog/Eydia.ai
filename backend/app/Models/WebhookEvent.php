<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Raw inbound webhook. NOT tenant-scoped by global scope: tenant_id is resolved
 * during async processing (from phone_number_id), and the webhook request is
 * unauthenticated. Isolation for reads is enforced in the admin/service layer.
 */
class WebhookEvent extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'source', 'event_key', 'object_type', 'payload',
        'processed', 'processed_at', 'error', 'attempts',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed' => 'boolean',
        'processed_at' => 'datetime',
    ];
}
