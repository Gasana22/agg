<?php

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $item_id
 * @property string|null $lot_id
 * @property string $expected_quantity
 * @property string $counted_quantity
 */
class StockAdjustmentLine extends Model
{
    use BelongsToFarm, HasUuids;

    public $timestamps = false;

    protected $fillable = ['farm_id', 'adjustment_id', 'item_id', 'lot_id', 'expected_quantity', 'counted_quantity'];

    protected function casts(): array
    {
        return ['expected_quantity' => 'decimal:3', 'counted_quantity' => 'decimal:3'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }
}
