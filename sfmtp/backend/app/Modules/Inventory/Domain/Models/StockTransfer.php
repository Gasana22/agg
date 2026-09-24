<?php

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Immutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A move of stock between two stores; its lines are stock movements. */
class StockTransfer extends Model
{
    use BelongsToFarm, HasUuids, Immutable;

    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['farm_id', 'code', 'from_location_id', 'to_location_id', 'note', 'transferred_by', 'occurred_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }
}
