<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkSendRecipient extends Model
{
    protected $fillable = [
        'bulk_send_id', 'phone', 'status', 'wamid', 'error_code', 'error_message', 'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function bulkSend(): BelongsTo
    {
        return $this->belongsTo(BulkSend::class);
    }
}
