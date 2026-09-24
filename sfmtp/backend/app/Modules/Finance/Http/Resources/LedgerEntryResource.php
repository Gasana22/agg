<?php

namespace App\Modules\Finance\Http\Resources;

use App\Modules\Finance\Domain\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LedgerEntry */
class LedgerEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'ledger_entry',
            'number' => $this->number,
            'posted_on' => $this->posted_on->toDateString(),
            'source' => ['type' => $this->source_type, 'id' => $this->source_id],
            'memo' => $this->memo,
            'reverses_entry_id' => $this->reverses_entry_id,
            'reversed_by' => $this->whenLoaded('reversal', fn () => $this->reversal ? ['id' => $this->reversal->id, 'number' => $this->reversal->number] : null),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => [
                'account' => ['id' => $l->account->id, 'code' => $l->account->code, 'name' => $l->account->name],
                'debit' => (float) $l->debit,
                'credit' => (float) $l->credit,
                'cost_center' => $l->cost_center_type ? ['type' => $l->cost_center_type, 'id' => $l->cost_center_id] : null,
                'memo' => $l->memo,
            ])->values()),
            'posted_by' => $this->whenLoaded('poster', fn () => $this->poster ? ['id' => $this->poster->id, 'name' => $this->poster->name] : null),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
