<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PoultryFlockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'farm_id' => $this->farm_id,
            'flock_code' => $this->flock_code,
            'name' => $this->name,
            'bird_type' => $this->bird_type,
            'breed' => $this->breed,
            'initial_count' => $this->initial_count,
            'current_count' => $this->currentCount(),
            'source' => $this->source,
            'acquired_date' => $this->acquired_date,
            'status' => $this->status,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
