<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CropSeasonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'farm_id' => $this->farm_id,
            'crop' => $this->whenLoaded('crop', fn () => [
                'id' => $this->crop->id,
                'name' => $this->crop->name,
                'variety' => $this->crop->variety,
            ]),
            'plot_id' => $this->plot_id,
            'season_name' => $this->season_name,
            'planned_planting_date' => $this->planned_planting_date,
            'actual_planting_date' => $this->actual_planting_date,
            'budget' => $this->budget,
            'expected_yield' => $this->expected_yield,
            'expected_yield_unit' => $this->expected_yield_unit,
            'status' => $this->status,
            'activities_count' => $this->whenCounted('activities'),
            'harvests_count' => $this->whenCounted('harvests'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
