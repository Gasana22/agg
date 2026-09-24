<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Domain\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockMovement */
class MovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $values = app(InventoryAccess::class)->seesValues();

        return [
            'id' => $this->id,
            'type' => $this->type,
            'item' => Refs::item($this->item),
            'location' => Refs::location($this->location),
            'lot' => Refs::lot($this->lot),
            'quantity' => (float) $this->quantity,
            'balance_after' => (float) $this->balance_after,
            'unit_cost' => $this->when($values, fn () => $this->unit_cost === null ? null : (float) $this->unit_cost),
            'value' => $this->when($values, fn () => (float) $this->value),
            'source' => ['type' => $this->source_type, 'id' => $this->source_id],
            'subject' => $this->subject_type ? ['type' => $this->subject_type, 'id' => $this->subject_id] : null,
            'ledger_entry_id' => $this->when($values, $this->ledger_entry_id),
            'note' => $this->note,
            'occurred_at' => $this->occurred_at->toIso8601ZuluString(),
            'recorded_by' => Refs::user($this->recorder),
        ];
    }
}
