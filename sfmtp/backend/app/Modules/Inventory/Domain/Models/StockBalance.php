<?php

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\FarmStructure\Domain\Models\Location;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock on hand of an item, in a store, for one lot (or untracked stock).
 * Only StockService writes it, under a row lock.
 *
 * @property string $id
 * @property string $item_id
 * @property string $location_id
 * @property string|null $lot_id
 * @property string $quantity
 * @property string $value
 */
class StockBalance extends Model
{
    use BelongsToFarm, HasUuids;

    public const CREATED_AT = null;

    protected $fillable = ['farm_id', 'item_id', 'location_id', 'lot_id', 'lot_key', 'quantity', 'value', 'allow_negative'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'value' => 'decimal:2', 'allow_negative' => 'boolean'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
