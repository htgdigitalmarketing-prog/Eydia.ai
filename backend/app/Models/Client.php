<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A client/brand shown in the public "Our Clients" section of the landing page.
 * Managed by the super admin; not tenant-scoped.
 */
class Client extends Model
{
    protected $fillable = ['name', 'logo_url', 'website', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Client $c) {
            if (empty($c->uuid)) {
                $c->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
