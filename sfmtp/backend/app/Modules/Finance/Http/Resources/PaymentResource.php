<?php

namespace App\Modules\Finance\Http\Resources;

use App\Modules\Finance\Domain\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'payment',
            'code' => $this->code,
            'direction' => $this->direction,
            'status' => $this->status,
            'payable' => ['type' => $this->payable_type, 'id' => $this->payable_id, 'code' => $this->payable_code],
            'party' => $this->party,
            'amount' => (float) $this->amount,
            'paid_on' => $this->paid_on->toDateString(),
            'method' => $this->method,
            'account' => Refs::account($this->account),
            'reference' => $this->reference,
            'note' => $this->note,
            'ledger_entry_id' => $this->ledger_entry_id,
            'recorded_by' => Refs::user($this->recorder),
            'voided_at' => $this->voided_at?->toIso8601ZuluString(),
            'void_reason' => $this->void_reason,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
