<?php

namespace App\Modules\Procurement\Http\Resources;

use App\Modules\Inventory\Http\Resources\Refs;
use App\Modules\Procurement\Application\ProcurementAccess;
use App\Modules\Procurement\Domain\Models\PurchaseRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PurchaseRequest */
class PurchaseRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $prices = app(ProcurementAccess::class)->seesPrices();

        return [
            'id' => $this->id,
            'type' => 'purchase_request',
            'code' => $this->code,
            'status' => $this->status,
            'needed_by' => $this->needed_by?->toDateString(),
            'reason' => $this->reason,
            'lines' => $this->lines->map(fn ($l) => [
                'id' => $l->id,
                'item' => Refs::item($l->item),
                'description' => $l->description,
                'quantity' => (float) $l->quantity,
                'unit' => $l->unit,
            ] + ($prices ? ['estimated_unit_price' => $l->estimated_unit_price === null ? null : (float) $l->estimated_unit_price] : []))->values(),
            'requested_by' => Refs::user($this->requester),
            'decided_by' => Refs::user($this->decider),
            'decided_at' => $this->decided_at?->toIso8601ZuluString(),
            'decision_note' => $this->decision_note,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
