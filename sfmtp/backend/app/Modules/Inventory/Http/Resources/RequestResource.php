<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Domain\Models\InventoryRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InventoryRequest */
class RequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'inventory_request',
            'code' => $this->code,
            'status' => $this->status,
            'subject' => ['type' => $this->subject_type, 'id' => $this->subject_id, 'label' => $this->subject_label],
            'task_id' => $this->task_id,
            'location' => Refs::location($this->location),
            'needed_on' => $this->needed_on?->toDateString(),
            'note' => $this->note,
            'lines' => $this->lines->map(fn ($l) => [
                'id' => $l->id,
                'item' => Refs::item($l->item),
                'quantity' => (float) $l->quantity,
                'issued_quantity' => (float) $l->issued_quantity,
            ])->values(),
            'requested_by' => Refs::user($this->requester),
            'decided_by' => Refs::user($this->decider),
            'decided_at' => $this->decided_at?->toIso8601ZuluString(),
            'decision_note' => $this->decision_note,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
