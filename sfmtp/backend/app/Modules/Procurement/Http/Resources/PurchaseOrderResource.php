<?php

namespace App\Modules\Procurement\Http\Resources;

use App\Modules\Inventory\Http\Resources\Refs;
use App\Modules\Procurement\Application\ProcurementAccess;
use App\Modules\Procurement\Domain\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PurchaseOrder */
class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $prices = app(ProcurementAccess::class)->seesPrices();

        return [
            'id' => $this->id,
            'type' => 'purchase_order',
            'code' => $this->code,
            'status' => $this->status,
            'supplier' => $this->supplier ? ['id' => $this->supplier->id, 'code' => $this->supplier->code, 'name' => $this->supplier->name, 'on_portal' => $this->supplier->party_id !== null] : null,
            'supplier_response' => $this->supplier_response,
            'supplier_promised_on' => $this->supplier_promised_on?->toDateString(),
            'supplier_note' => $this->supplier_note,
            'supplier_responded_at' => $this->supplier_responded_at?->toIso8601ZuluString(),
            'purchase_request' => $this->whenLoaded('request', fn () => $this->request ? ['id' => $this->request->id, 'code' => $this->request->code] : null),
            'expected_on' => $this->expected_on?->toDateString(),
            'delivery_location' => $this->whenLoaded('deliveryLocation', fn () => Refs::location($this->deliveryLocation)),
            'currency' => $this->currency,
            'total_amount' => $this->when($prices, fn () => (float) $this->total_amount),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => [
                'id' => $l->id,
                'item' => Refs::item($l->item),
                'description' => $l->description,
                'quantity' => (float) $l->quantity,
                'received_quantity' => (float) $l->received_quantity,
                'invoiced_quantity' => (float) $l->invoiced_quantity,
                'confirmed_quantity' => $l->confirmed_quantity === null ? null : (float) $l->confirmed_quantity,
            ] + ($prices ? ['unit_price' => (float) $l->unit_price] : []))->values()),
            'deliveries' => $this->whenLoaded('deliveries', fn () => $this->deliveries->map(fn ($d) => [
                'id' => $d->id, 'code' => $d->code, 'received_on' => $d->received_on->toDateString(), 'supplier_reference' => $d->supplier_reference,
                'location' => Refs::location($d->location), 'received_by' => Refs::user($d->receiver),
                'lines' => $d->lines->map(fn ($dl) => ['order_line_id' => $dl->order_line_id, 'quantity' => (float) $dl->quantity, 'lot' => Refs::lot($dl->lot)])->values(),
            ])->values()),
            'invoices' => $this->when($prices && $this->relationLoaded('invoices'), fn () => $this->invoices->map(fn ($i) => [
                'id' => $i->id, 'code' => $i->code, 'invoice_number' => $i->invoice_number, 'invoice_date' => $i->invoice_date->toDateString(),
                'due_on' => $i->due_on?->toDateString(), 'amount' => (float) $i->amount, 'status' => $i->status,
            ])->values()),
            'dispatches' => $this->whenLoaded('dispatches', fn () => $this->dispatches->sortByDesc('created_at')->map(fn ($d) => $d->toPortal() + ['media_id' => $d->media_id])->values()),
            'invoice_submissions' => $this->when($prices && $this->relationLoaded('submissions'), fn () => $this->submissions->sortByDesc('created_at')->map(fn ($s) => $s->toPortal() + ['media_id' => $s->media_id])->values()),
            'notes' => $this->notes,
            'created_by' => $this->whenLoaded('creator', fn () => Refs::user($this->creator)),
            'approved_by' => $this->whenLoaded('approver', fn () => Refs::user($this->approver)),
            'approved_at' => $this->approved_at?->toIso8601ZuluString(),
            'sent_at' => $this->sent_at?->toIso8601ZuluString(),
            'cancel_reason' => $this->cancel_reason,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
