<?php

namespace App\Modules\Finance\Http\Resources;

use App\Modules\Finance\Domain\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Expense */
class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'expense',
            'code' => $this->code,
            'status' => $this->status,
            'account' => Refs::account($this->account),
            'amount' => (float) $this->amount,
            'paid_amount' => (float) $this->paid_amount,
            'spent_on' => $this->spent_on->toDateString(),
            'payee' => $this->payee,
            'description' => $this->description,
            'cost_center' => Refs::center($this->cost_center_type, $this->cost_center_id, $this->cost_center_label),
            'paid_from' => Refs::account($this->paidFrom),
            'media_id' => $this->media_id,
            'ledger_entry_id' => $this->ledger_entry_id,
            'requested_by' => Refs::user($this->requester),
            'decided_by' => Refs::user($this->decider),
            'decided_at' => $this->decided_at?->toIso8601ZuluString(),
            'decision_note' => $this->decision_note,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
