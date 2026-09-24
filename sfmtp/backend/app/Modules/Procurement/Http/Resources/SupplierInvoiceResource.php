<?php

namespace App\Modules\Procurement\Http\Resources;

use App\Modules\Inventory\Http\Resources\Refs;
use App\Modules\Procurement\Domain\Models\SupplierInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SupplierInvoice */
class SupplierInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'supplier_invoice',
            'code' => $this->code,
            'invoice_number' => $this->invoice_number,
            'supplier' => ['id' => $this->supplier->id, 'code' => $this->supplier->code, 'name' => $this->supplier->name],
            'order' => ['id' => $this->order->id, 'code' => $this->order->code],
            'invoice_date' => $this->invoice_date->toDateString(),
            'due_on' => $this->due_on?->toDateString(),
            'amount' => (float) $this->amount,
            'paid_amount' => (float) $this->paid_amount,
            'status' => $this->status,
            'cancel_reason' => $this->cancel_reason,
            'lines' => $this->lines->map(fn ($l) => [
                'order_line_id' => $l->order_line_id,
                'item' => Refs::item($l->orderLine->item),
                'quantity' => (float) $l->quantity,
                'unit_price' => (float) $l->unit_price,
                'order_unit_price' => (float) $l->orderLine->unit_price,
            ])->values(),
            'ledger_entry_id' => $this->ledger_entry_id,
            'notes' => $this->notes,
            'recorded_by' => Refs::user($this->recorder),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
