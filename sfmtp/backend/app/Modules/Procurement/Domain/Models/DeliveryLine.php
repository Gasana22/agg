<?php

namespace App\Modules\Procurement\Domain\Models;

use App\Modules\Inventory\Domain\Models\StockLot;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Immutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One received line: the quantity, and the lot and stock movement it created.
 */
class DeliveryLine extends Model
{
    use BelongsToFarm, HasUuids, Immutable;

    public $timestamps = false;

    protected $fillable = ['farm_id', 'delivery_id', 'order_line_id', 'quantity', 'lot_id', 'movement_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'order_line_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }
}
