<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CropResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'farm_id' => $this->farm_id,
            'name' => $this->name,
            'variety' => $this->variety,
            'category' => $this->category,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'seasons_count' => $this->whenCounted('seasons'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
