<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SocialPost extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'caption', 'image_url', 'media_type', 'image_path', 'targets',
        'status', 'scheduled_at', 'published_at', 'results', 'created_by',
    ];

    protected $casts = [
        'targets' => 'array',
        'results' => 'array',
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
    ];
}
