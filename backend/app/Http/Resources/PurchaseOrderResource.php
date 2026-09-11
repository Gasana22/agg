<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'farm_id' => $this->farm_id,
            'supplier' => $this->whenLoaded('supplier', fn () => [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
            ]),
            'order_date' => $this->order_date,
            'expected_delivery_date' => $this->expected_delivery_date,
            'status' => $this->status,
            'notes' => $this->notes,
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
            'total_amount' => $this->whenLoaded('items', fn () => $this->totalAmount()),
            'total_paid' => $this->whenLoaded('payments', fn () => $this->totalPaid()),
            'balance' => $this->when(
                $this->relationLoaded('items') && $this->relationLoaded('payments'),
                fn () => $this->balance()
            ),
            'creator' => $this->whenLoaded('creator', fn () => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'deliveries' => DeliveryResource::collection($this->whenLoaded('deliveries')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at,
        ];
    }
}
