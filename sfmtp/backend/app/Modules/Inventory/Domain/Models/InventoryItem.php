<?php

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\Catalog\Domain\Models\InventoryCategory;
use App\Modules\FarmStructure\Domain\Models\Location;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something the farm keeps in stock: seed, fertilizer, feed, drugs, tools …
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $unit
 * @property bool $tracks_lots
 * @property bool $tracks_expiry
 * @property string|null $reorder_level
 */
class InventoryItem extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $fillable = ['farm_id', 'code', 'name', 'category_id', 'unit', 'sku', 'reorder_level', 'tracks_lots', 'tracks_expiry', 'default_location_id', 'is_active', 'notes'];

    protected function casts(): array
    {
        return ['reorder_level' => 'decimal:3', 'tracks_lots' => 'boolean', 'tracks_expiry' => 'boolean', 'is_active' => 'boolean', 'version' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(InventoryCategory::class, 'category_id');
    }

    public function defaultLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'default_location_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class, 'item_id');
    }
}
