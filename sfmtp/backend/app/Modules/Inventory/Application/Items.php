<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Support\Database\Codes;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\DB;

/** The farm's item list. Tracking lots cannot be switched off once stock exists. */
class Items
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(array $data): InventoryItem
    {
        $this->assertUnit($data['unit']);
        $item = InventoryItem::create($data + ['code' => Codes::next(InventoryItem::class, 'ITM')]);
        $this->audit->record('inventory.item.created', $item, null, $item->only(['code', 'name', 'unit', 'category_id', 'tracks_lots']));

        return $item->refresh();
    }

    public function update(InventoryItem $item, array $data): InventoryItem
    {
        if (isset($data['unit']) && $data['unit'] !== $item->unit) {
            if ($this->hasStock($item)) {
                throw ApiException::conflict('item_has_stock', 'The unit cannot change while the item has stock movements.');
            }
            $this->assertUnit($data['unit']);
        }
        if (array_key_exists('tracks_lots', $data) && (bool) $data['tracks_lots'] !== $item->tracks_lots && $this->hasStock($item)) {
            throw ApiException::conflict('item_has_stock', 'Lot tracking cannot change once the item has stock movements.');
        }
        $old = $item->only(array_keys($data));
        $item->fill($data)->save();
        $this->audit->record('inventory.item.updated', $item, $old, $data);

        return $item;
    }

    private function hasStock(InventoryItem $item): bool
    {
        return DB::table('stock_movements')->where('farm_id', $item->farm_id)->where('item_id', $item->id)->exists();
    }

    private function assertUnit(string $unit): void
    {
        if (! DB::table('units')->where('code', $unit)->exists()) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['unit' => ['Unknown unit.']]);
        }
    }
}
