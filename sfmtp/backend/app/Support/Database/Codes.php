<?php

namespace App\Support\Database;

use Illuminate\Database\Eloquent\Model;

/** Short, farm-unique, human codes such as CP-003, TSK-012 and WRK-004. */
final class Codes
{
    /** @param  class-string<Model>  $model */
    public static function next(string $model, string $prefix, string $column = 'code'): string
    {
        $n = $model::count() + 1;
        do {
            $code = $prefix.'-'.str_pad((string) $n++, 3, '0', STR_PAD_LEFT);
        } while ($model::where($column, $code)->exists());

        return $code;
    }
}
