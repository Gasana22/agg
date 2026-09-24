<?php

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Domain\Models\CustomerInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CustomerInvoice */
class CustomerInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $today = now()->toDateString();

        return [
            'id' => $this->id,
            'type' => 'customer_invoice',
            'code' => $this->code,
            'status' => $this->status,
            'customer' => $this->customer ? ['id' => $this->customer->id, 'code' => $this->customer->code, 'name' => $this->customer->name] : null,
            'invoice_date' => $this->invoice_date->toDateString(),
            'due_on' => $this->due_on?->toDateString(),
            'overdue' => $this->status === 'issued' && $this->due_on !== null && $this->due_on->toDateString() < $today,
            'amount' => (float) $this->amount,
            'paid_amount' => (float) $this->paid_amount,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => [
                'id' => $l->id,
                'description' => $l->description,
                'quantity' => (float) $l->quantity,
                'unit' => $l->unit,
                'unit_price' => (float) $l->unit_price,
                'amount' => (float) $l->amount,
                'account' => $l->account ? ['id' => $l->account->id, 'code' => $l->account->code, 'name' => $l->account->name] : null,
                'cost_center' => $l->cost_center_type ? ['type' => $l->cost_center_type, 'id' => $l->cost_center_id, 'label' => $l->cost_center_label] : null,
                'animal_sale_id' => $l->animal_sale_id,
            ])->values()),
            'notes' => $this->notes,
            'ledger_entry_id' => $this->ledger_entry_id,
            'created_by' => $this->creator ? ['id' => $this->creator->id, 'name' => $this->creator->name] : null,
            'issued_by' => $this->issuer ? ['id' => $this->issuer->id, 'name' => $this->issuer->name] : null,
            'issued_at' => $this->issued_at?->toIso8601ZuluString(),
            'voided_at' => $this->voided_at?->toIso8601ZuluString(),
            'void_reason' => $this->void_reason,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
