<?php

namespace App\Support\Concerns;

/**
 * Auto-fills created_by / updated_by from the authenticated user.
 * Blame columns are intentionally FK-free (nullable bigint) — they record who
 * touched a row for audit, not a hard referential constraint.
 */
trait TracksBlame
{
    public static function bootTracksBlame(): void
    {
        static::creating(function ($model) {
            $id = auth()->id();
            if ($id !== null) {
                $model->created_by ??= $id;
                $model->updated_by ??= $id;
            }
        });

        static::updating(function ($model) {
            $id = auth()->id();
            if ($id !== null) {
                $model->updated_by = $id;
            }
        });
    }
}
