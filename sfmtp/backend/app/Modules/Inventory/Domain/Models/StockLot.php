<?php

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A received lot of an item, with its own trace batch (`input_lot`).
 *
 * @property string $id
 * @property string $item_id
 * @property string $code
 * @property string|null $lot_number
 * @property CarbonImmutable|null $expires_on
 * @property string|null $trace_batch_id
 */
class StockLot extends Model
{
    use BelongsToFarm, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['farm_id', 'item_id', 'code', 'lot_number', 'expires_on', 'received_on', 'unit_cost', 'supplier_id', 'trace_batch_id', 'source_type', 'source_id'];

    protected function casts(): array
    {
        return ['expires_on' => 'immutable_date', 'received_on' => 'immutable_date', 'unit_cost' => 'decimal:4'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(TraceBatch::class, 'trace_batch_id');
    }
}
