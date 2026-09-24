<?php

namespace App\Modules\Procurement\Domain\Models;

use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a purchase request.
 */
class PurchaseRequestLine extends Model
{
    use BelongsToFarm, HasUuids;

    public $timestamps = false;

    protected $fillable = ['farm_id', 'request_id', 'item_id', 'description', 'quantity', 'unit', 'estimated_unit_price'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'estimated_unit_price' => 'decimal:4'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }
}
