<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CropHarvestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'crop_season_id' => $this->crop_season_id,
            'harvest_date' => $this->harvest_date,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'quality_grade' => $this->quality_grade,
            'notes' => $this->notes,
            'recorder' => $this->whenLoaded('recorder', fn () => [
                'id' => $this->recorder->id,
                'name' => $this->recorder->name,
            ]),
            'sales_count' => $this->whenCounted('sales'),
            'created_at' => $this->created_at,
        ];
    }
}
