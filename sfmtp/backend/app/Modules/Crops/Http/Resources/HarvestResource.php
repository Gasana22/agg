<?php

namespace App\Modules\Crops\Http\Resources;

use App\Modules\Crops\Domain\Models\CropHarvest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CropHarvest */
class HarvestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'crop_harvest',
            'cycle_id' => $this->cycle_id,
            'cycle' => $this->whenLoaded('cycle', fn () => [
                'id' => $this->cycle->id,
                'code' => $this->cycle->code,
                'crop' => $this->cycle->relationLoaded('crop') ? $this->cycle->crop->label() : null,
                'plot' => $this->cycle->relationLoaded('plot') ? $this->cycle->plot->code : null,
            ]),
            'harvested_on' => $this->harvested_on->toDateString(),
            'quantity' => (float) $this->quantity,
            'unit' => $this->unit,
            'quality_grade' => $this->quality_grade,
            'moisture_pct' => $this->moisture_pct === null ? null : (float) $this->moisture_pct,
            'notes' => $this->notes,
            'batch' => $this->whenLoaded('batch', fn () => ['id' => $this->batch->id, 'batch_code' => $this->batch->batch_code]),
            'withholding_override_reason' => $this->withholding_override_reason,
            'recorded_by' => $this->whenLoaded('recorder', fn () => $this->recorder ? ['id' => $this->recorder->id, 'name' => $this->recorder->name] : null),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
