<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CropSaleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'crop_harvest_id' => $this->crop_harvest_id,
            'buyer_name' => $this->buyer_name,
            'quantity_sold' => $this->quantity_sold,
            'unit_price' => $this->unit_price,
            'revenue' => $this->revenue(),
            'sale_date' => $this->sale_date,
            'notes' => $this->notes,
            'recorder' => $this->whenLoaded('recorder', fn () => [
                'id' => $this->recorder->id,
                'name' => $this->recorder->name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
