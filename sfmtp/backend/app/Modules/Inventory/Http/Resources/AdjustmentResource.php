<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Application\Qty;
use App\Modules\Inventory\Domain\Models\StockAdjustment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockAdjustment */
class AdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'stock_adjustment',
            'code' => $this->code,
            'status' => $this->status,
            'location' => Refs::location($this->location),
            'reason' => $this->reason,
            'lines' => $this->lines->map(fn ($l) => [
                'id' => $l->id,
                'item' => Refs::item($l->item),
                'lot' => Refs::lot($l->lot),
                'expected_quantity' => (float) $l->expected_quantity,
                'counted_quantity' => (float) $l->counted_quantity,
                'difference' => (float) Qty::of(Qty::milli($l->counted_quantity) - Qty::milli($l->expected_quantity)),
            ])->values(),
            'value_change' => $this->when(app(InventoryAccess::class)->seesValues(), fn () => $this->value_change === null ? null : (float) $this->value_change),
            'proposed_by' => Refs::user($this->proposer),
            'decided_by' => Refs::user($this->decider),
            'decided_at' => $this->decided_at?->toIso8601ZuluString(),
            'decision_note' => $this->decision_note,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
