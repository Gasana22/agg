<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inventory_item_id' => $this->inventory_item_id,
            'type' => $this->type,
            'quantity' => $this->quantity,
            'date' => $this->date,
            'delivery_id' => $this->delivery_id,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'recorder' => $this->whenLoaded('recorder', fn () => [
                'id' => $this->recorder->id,
                'name' => $this->recorder->name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
