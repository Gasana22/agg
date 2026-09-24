<?php

namespace App\Modules\Finance\Http\Resources;

use App\Modules\Finance\Domain\Models\IncomeRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin IncomeRecord */
class IncomeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'income',
            'code' => $this->code,
            'status' => $this->status,
            'account' => Refs::account($this->account),
            'received_into' => Refs::account($this->receivedInto),
            'amount' => (float) $this->amount,
            'received_on' => $this->received_on->toDateString(),
            'payer' => $this->payer,
            'description' => $this->description,
            'cost_center' => Refs::center($this->cost_center_type, $this->cost_center_id, $this->cost_center_label),
            'media_id' => $this->media_id,
            'ledger_entry_id' => $this->ledger_entry_id,
            'recorded_by' => Refs::user($this->recorder),
            'voided_at' => $this->voided_at?->toIso8601ZuluString(),
            'void_reason' => $this->void_reason,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
