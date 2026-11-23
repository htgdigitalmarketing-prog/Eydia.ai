<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A portfolio item shown in the public "Our Work" section, grouped by category.
 * Managed by the super admin; not tenant-scoped.
 */
class WorkItem extends Model
{
    protected $fillable = ['title', 'category', 'image_url', 'link', 'description', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (WorkItem $w) {
            if (empty($w->uuid)) {
                $w->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
