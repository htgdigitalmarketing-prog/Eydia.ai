<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TracksBlame;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SocialConnection extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, TracksBlame;

    protected $fillable = [
        'tenant_id', 'page_id', 'page_name', 'page_access_token',
        'ig_user_id', 'ig_username', 'status', 'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'page_access_token' => 'encrypted',
    ];

    protected $hidden = [
        'page_access_token',
    ];
}
