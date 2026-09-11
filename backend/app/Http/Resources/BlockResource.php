<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlockResource extends JsonResource
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
            'gps_lat' => $this->gps_lat,
            'gps_lng' => $this->gps_lng,
            'is_active' => $this->is_active,
            'sections_count' => $this->whenCounted('sections'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
