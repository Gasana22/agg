<?php

namespace App\Modules\Procurement\Domain\Models;

use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a purchase order, with what has been received and invoiced.
 */
class PurchaseOrderLine extends Model
{
    use BelongsToFarm, HasUuids;

    public $timestamps = false;

    protected $fillable = ['farm_id', 'order_id', 'item_id', 'description', 'quantity', 'unit_price', 'received_quantity', 'invoiced_quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:4', 'received_quantity' => 'decimal:3', 'invoiced_quantity' => 'decimal:3', 'confirmed_quantity' => 'decimal:3'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'order_id');
    }
}
