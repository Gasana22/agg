<?php

namespace App\Modules\Crops\Http\Resources;

use App\Modules\Crops\Application\Units;
use App\Modules\Crops\Domain\Models\CropCycle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CropCycle */
class CycleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $batch = fn ($b) => $b ? ['id' => $b->id, 'batch_code' => $b->batch_code, 'status' => $b->status->value] : null;

        return [
            'id' => $this->id,
            'type' => 'crop_cycle',
            'code' => $this->code,
            'stage' => $this->stage->value,
            'planting_method' => $this->planting_method->value,
            'plot' => $this->whenLoaded('plot', fn () => ['id' => $this->plot->id, 'code' => $this->plot->code, 'name' => $this->plot->name]),
            'crop' => $this->whenLoaded('crop', fn () => ['id' => $this->crop->id, 'label' => $this->crop->label()]),
            'plan' => $this->whenLoaded('plan', fn () => $this->plan ? ['id' => $this->plan->id, 'code' => $this->plan->code, 'name' => $this->plan->name] : null),
            'season' => $this->whenLoaded('season', fn () => $this->season ? ['id' => $this->season->id, 'name' => $this->season->name] : null),
            'area_ha' => (float) $this->area_ha,
            'sown_on' => $this->sown_on?->toDateString(),
            'planted_on' => $this->planted_on?->toDateString(),
            'expected_harvest_on' => $this->expected_harvest_on?->toDateString(),
            'safe_harvest_on' => $this->safe_harvest_on?->toDateString(),
            'expected_yield' => $this->expected_yield === null ? null : (float) $this->expected_yield,
            'actual_yield' => $this->whenLoaded('harvests', fn () => $this->actualYield()),
            'yield_unit' => $this->yield_unit,
            'nursery' => $this->planting_method->value === 'transplant' ? [
                'seeds_sown' => $this->seeds_sown,
                'seedlings_germinated' => $this->seedlings_germinated,
                'seedlings_transplanted' => $this->seedlings_transplanted,
            ] : null,
            'seed_batch' => $this->whenLoaded('seedBatch', fn () => $batch($this->seedBatch)),
            'nursery_batch' => $this->whenLoaded('nurseryBatch', fn () => $batch($this->nurseryBatch)),
            'crop_lot' => $this->whenLoaded('cropLot', fn () => $batch($this->cropLot)),
            'open_observations' => $this->whenHas('open_observations_count', fn () => (int) $this->open_observations_count),
            'operations_count' => $this->whenHas('operations_count', fn () => (int) $this->operations_count),
            'closed_on' => $this->closed_on?->toDateString(),
            'close_reason' => $this->close_reason?->value,
            'notes' => $this->notes,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }

    /** Harvested quantity in the cycle's yield unit (harvests in other dimensions are skipped). */
    private function actualYield(): float
    {
        $units = app(Units::class);
        $total = 0.0;
        foreach ($this->harvests as $h) {
            $total += $units->convert((float) $h->quantity, $h->unit, $this->yield_unit) ?? 0.0;
        }

        return round($total, 3);
    }
}
