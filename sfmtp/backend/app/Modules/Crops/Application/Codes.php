<?php

namespace App\Modules\Crops\Application;

use Illuminate\Database\Eloquent\Model;

/** Short, farm-unique, human codes such as CP-003 and CC-012. */
final class Codes
{
    /** @param  class-string<Model>  $model */
    public static function next(string $model, string $prefix): string
    {
        $n = $model::count() + 1;
        do {
            $code = $prefix.'-'.str_pad((string) $n++, 3, '0', STR_PAD_LEFT);
        } while ($model::where('code', $code)->exists());

        return $code;
    }
}
