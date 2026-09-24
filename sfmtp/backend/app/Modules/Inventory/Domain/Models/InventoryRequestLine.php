<?php

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $item_id
 * @property string $quantity
 * @property string $issued_quantity
 */
class InventoryRequestLine extends Model
{
    use BelongsToFarm, HasUuids;

    public $timestamps = false;

    protected $fillable = ['farm_id', 'request_id', 'item_id', 'quantity', 'issued_quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'issued_quantity' => 'decimal:3'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }
}
