<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'farm_id' => $this->farm_id,
            'name' => $this->name,
            'category' => $this->category,
            'unit' => $this->unit,
            'reorder_level' => $this->reorder_level,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'current_quantity' => $this->currentQuantity(),
            'is_low_stock' => $this->isLowStock(),
            'created_at' => $this->created_at,
        ];
    }
}
