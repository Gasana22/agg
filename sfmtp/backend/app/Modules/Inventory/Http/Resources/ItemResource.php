<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InventoryItem */
class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $values = app(InventoryAccess::class)->seesValues();
        $onHand = $this->on_hand ?? null;

        return [
            'id' => $this->id,
            'type' => 'inventory_item',
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->whenLoaded('category', fn () => ['id' => $this->category->id, 'code' => $this->category->code, 'name' => $this->category->name, 'kind' => $this->category->kind]),
            'unit' => $this->unit,
            'sku' => $this->sku,
            'reorder_level' => $this->reorder_level === null ? null : (float) $this->reorder_level,
            'tracks_lots' => $this->tracks_lots,
            'tracks_expiry' => $this->tracks_expiry,
            'default_location' => $this->whenLoaded('defaultLocation', fn () => Refs::location($this->defaultLocation)),
            'is_active' => $this->is_active,
            'on_hand' => $onHand === null ? null : (float) $onHand,
            'low_stock' => $onHand !== null && $this->reorder_level !== null && (float) $onHand <= (float) $this->reorder_level,
            'stock_value' => $this->when($values && isset($this->stock_value), fn () => (float) $this->stock_value),
            'notes' => $this->notes,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
