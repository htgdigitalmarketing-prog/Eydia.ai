<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageAttachment extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'message_id', 'type', 'meta_media_id', 'mime_type',
        'file_name', 'file_size', 'storage_disk', 'storage_path',
        'sha256', 'caption',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
