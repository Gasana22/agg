<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_order_id' => $this->purchase_order_id,
            'delivery_date' => $this->delivery_date,
            'is_complete' => $this->is_complete,
            'notes' => $this->notes,
            'receiver' => $this->whenLoaded('receiver', fn () => [
                'id' => $this->receiver->id,
                'name' => $this->receiver->name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
