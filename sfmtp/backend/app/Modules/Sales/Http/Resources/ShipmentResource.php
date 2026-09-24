<?php

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Domain\Models\Shipment;
use App\Modules\Sales\Domain\Models\ShipmentLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Shipment */
class ShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'shipment',
            'code' => $this->code,
            'status' => $this->status,
            'customer' => $this->whenLoaded('customer', fn () => ['id' => $this->customer->id, 'code' => $this->customer->code, 'name' => $this->customer->name]),
            'invoice' => $this->customer_invoice_id ? ['id' => $this->customer_invoice_id, 'code' => $this->invoice?->code] : null,
            'trace_batch' => $this->whenLoaded('batch', fn () => ['id' => $this->batch->id, 'batch_code' => $this->batch->batch_code, 'status' => $this->batch->status->value]),
            'destination' => $this->destination,
            'vehicle' => $this->vehicle,
            'driver' => $this->driver,
            'notes' => $this->notes,
            'dispatched_at' => $this->dispatched_at?->toIso8601ZuluString(),
            'dispatched_by' => $this->dispatched_by,
            'delivered_at' => $this->delivered_at?->toIso8601ZuluString(),
            'received_by' => $this->received_by,
            'failure_reason' => $this->failure_reason,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn (ShipmentLine $l) => [
                'id' => $l->id,
                'batch' => $l->batch ? ['id' => $l->batch->id, 'batch_code' => $l->batch->batch_code, 'kind' => $l->batch->kind->value, 'name' => $l->batch->name] : null,
                'quantity' => $l->quantity === null ? null : ['value' => $l->quantity, 'unit' => $l->unit],
                'description' => $l->description,
            ])->all()),
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
