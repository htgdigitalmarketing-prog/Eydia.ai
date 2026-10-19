<?php

namespace App\Support\Concerns;

use Illuminate\Support\Str;

/**
 * Gives a model a public, unguessable `uuid` alongside its internal bigint id.
 * Internal FKs use the fast bigint id; the uuid is what we expose over the API.
 */
trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
