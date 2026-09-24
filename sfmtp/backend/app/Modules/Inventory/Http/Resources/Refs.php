<?php

namespace App\Modules\Inventory\Http\Resources;

use Illuminate\Database\Eloquent\Model;

/** Small nested shapes shared by the inventory resources. */
final class Refs
{
    public static function item(?Model $i): ?array
    {
        return $i ? ['id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'unit' => $i->unit] : null;
    }

    public static function location(?Model $l): ?array
    {
        return $l ? ['id' => $l->id, 'code' => $l->code, 'name' => $l->name] : null;
    }

    public static function lot(?Model $l): ?array
    {
        return $l ? ['id' => $l->id, 'code' => $l->code, 'lot_number' => $l->lot_number, 'expires_on' => $l->expires_on?->toDateString(), 'trace_batch_id' => $l->trace_batch_id] : null;
    }

    public static function user(?Model $u): ?array
    {
        return $u ? ['id' => $u->id, 'name' => $u->name] : null;
    }
}
