<?php

namespace App\Modules\Sales\Domain\Models;

use App\Modules\Finance\Domain\Models\LedgerAccount;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something the farm sells, at its list price. Published products appear
 * in the customer portal of the farm's linked customers.
 */
class Product extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $fillable = ['farm_id', 'code', 'name', 'description', 'category', 'unit', 'list_price', 'currency', 'min_order_quantity', 'availability_note',
        'inventory_item_id', 'income_account_id', 'media_id', 'is_published', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['list_price' => 'decimal:2', 'min_order_quantity' => 'decimal:3', 'is_published' => 'boolean', 'is_active' => 'boolean', 'version' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function incomeAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'income_account_id');
    }
}
