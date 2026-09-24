<?php

namespace App\Modules\Crops\Domain\Models;

/** Optimistic version, bumped on every update (docs/06 §1 "Writes"). */
trait Versioned
{
    public static function bootVersioned(): void
    {
        static::updating(fn ($model) => $model->version = $model->getOriginal('version') + 1);
    }
}
