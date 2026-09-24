<?php

namespace App\Modules\Crops\Http\Resources;

use App\Modules\Crops\Application\CropAccess;
use App\Modules\Crops\Domain\Models\CropPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CropPlan */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'crop_plan',
            'code' => $this->code,
            'name' => $this->name,
            'status' => $this->status->value,
            'season' => $this->whenLoaded('season', fn () => ['id' => $this->season->id, 'name' => $this->season->name]),
            'crop' => $this->whenLoaded('crop', fn () => ['id' => $this->crop->id, 'label' => $this->crop->label()]),
            'planned_area_ha' => (float) $this->planned_area_ha,
            'expected_yield' => $this->expected_yield === null ? null : (float) $this->expected_yield,
            'yield_unit' => $this->yield_unit,
            'budget_amount' => $this->when(app(CropAccess::class)->seesMoney(), fn () => $this->budget_amount === null ? null : (float) $this->budget_amount),
            'notes' => $this->notes,
            'cycles_count' => $this->whenHas('cycles_count'),
            'planted_area_ha' => $this->whenHas('planted_area_ha', fn () => round((float) $this->planted_area_ha, 4)),
            'created_by' => $this->created_by,
            'approved_at' => $this->approved_at?->toIso8601ZuluString(),
            'closed_at' => $this->closed_at?->toIso8601ZuluString(),
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
