<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_order_id' => $this->purchase_order_id,
            'item_name' => $this->item_name,
            'category' => $this->category,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'unit_price' => $this->unit_price,
            'line_total' => $this->lineTotal(),
        ];
    }
}
