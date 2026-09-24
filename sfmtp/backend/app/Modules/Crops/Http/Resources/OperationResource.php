<?php

namespace App\Modules\Crops\Http\Resources;

use App\Modules\Crops\Application\CropAccess;
use App\Modules\Crops\Domain\Models\CropOperation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CropOperation */
class OperationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'crop_operation',
            'cycle_id' => $this->cycle_id,
            'cycle' => $this->whenLoaded('cycle', fn () => ['id' => $this->cycle->id, 'code' => $this->cycle->code]),
            'observation_id' => $this->observation_id,
            'operation' => $this->type->value,
            'status' => $this->status->value,
            'occurred_at' => $this->occurred_at->toIso8601ZuluString(),
            'notes' => $this->notes,
            'labour_hours' => $this->labour_hours === null ? null : (float) $this->labour_hours,
            'cost_amount' => $this->when(app(CropAccess::class)->seesMoney(), fn () => $this->cost_amount === null ? null : (float) $this->cost_amount),
            'latitude' => $this->latitude === null ? null : (float) $this->latitude,
            'longitude' => $this->longitude === null ? null : (float) $this->longitude,
            'inputs' => $this->whenLoaded('inputs', fn () => $this->inputs->map(fn ($i) => [
                'id' => $i->id,
                'product_name' => $i->product_name,
                'quantity' => (float) $i->quantity,
                'unit' => $i->unit,
                'withholding_days' => $i->withholding_days,
                'input_batch_id' => $i->input_batch_id,
            ])->values()),
            'recorded_by' => $this->whenLoaded('recorder', fn () => $this->recorder ? ['id' => $this->recorder->id, 'name' => $this->recorder->name] : null),
            'verified_by' => $this->whenLoaded('verifier', fn () => $this->verifier ? ['id' => $this->verifier->id, 'name' => $this->verifier->name] : null),
            'verified_at' => $this->verified_at?->toIso8601ZuluString(),
            'rejection_reason' => $this->rejection_reason,
            'version' => $this->version,
        ];
    }
}
