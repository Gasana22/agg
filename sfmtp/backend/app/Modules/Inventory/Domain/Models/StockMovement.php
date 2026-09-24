<?php

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\FarmStructure\Domain\Models\Location;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Immutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the stock ledger. Never changed.
 *
 * @property string $id
 * @property string $type
 * @property string $quantity
 * @property string $value
 */
class StockMovement extends Model
{
    use BelongsToFarm, HasUuids, Immutable;

    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['farm_id', 'item_id', 'lot_id', 'location_id', 'type', 'quantity', 'unit_cost', 'value', 'balance_after', 'source_type', 'source_id',
        'subject_type', 'subject_id', 'ledger_entry_id', 'note', 'occurred_at', 'recorded_by'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_cost' => 'decimal:4', 'value' => 'decimal:2', 'balance_after' => 'decimal:3', 'occurred_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
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

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
