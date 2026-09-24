<?php

namespace App\Modules\Livestock\Http\Resources;

use App\Modules\Access\Application\ScopedAccess;
use App\Modules\Livestock\Domain\Models\SaleRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SaleRequest */
class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $money = app(ScopedAccess::class)->seesMoney();

        return [
            'id' => $this->id,
            'type' => 'animal_sale_request',
            'code' => $this->code,
            'animal' => $this->whenLoaded('animal', fn () => ['id' => $this->animal->id, 'animal_code' => $this->animal->animal_code, 'name' => $this->animal->name, 'label' => $this->animal->label()]),
            'reason' => $this->reason,
            'buyer' => $this->buyer,
            'expected_price' => $this->when($money, fn () => $this->expected_price === null ? null : (float) $this->expected_price),
            'sale_price' => $this->when($money, fn () => $this->sale_price === null ? null : (float) $this->sale_price),
            'status' => $this->status->value,
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester ? ['id' => $this->requester->id, 'name' => $this->requester->name] : null),
            'decided_at' => $this->decided_at?->toIso8601ZuluString(),
            'decision_note' => $this->decision_note,
            'sold_on' => $this->sold_on?->toDateString(),
            'withdrawal_override_reason' => $this->withdrawal_override_reason,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
