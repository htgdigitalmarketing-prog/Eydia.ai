<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TracksBlame;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BlacklistEntry extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, TracksBlame;

    protected $fillable = [
        'tenant_id', 'phone', 'name', 'reason', 'source',
    ];

    /** Normalize a phone to digits only for consistent matching. */
    public static function normalize(string $phone): string
    {
        return preg_replace('/\D/', '', $phone) ?? $phone;
    }
}
