<?php

namespace App\Modules\Finance\Http\Resources;

use Illuminate\Database\Eloquent\Model;

/** Small nested shapes shared by the finance resources. */
final class Refs
{
    public static function account(?Model $a): ?array
    {
        return $a ? ['id' => $a->id, 'code' => $a->code, 'name' => $a->name] : null;
    }

    public static function user(?Model $u): ?array
    {
        return $u ? ['id' => $u->id, 'name' => $u->name] : null;
    }

    public static function center(?string $type, ?string $id, ?string $label): ?array
    {
        return $type ? ['type' => $type, 'id' => $id, 'label' => $label] : null;
    }
}
