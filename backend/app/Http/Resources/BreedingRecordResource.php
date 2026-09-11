<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BreedingRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'farm_id' => $this->farm_id,
            'dam' => $this->whenLoaded('dam', fn () => [
                'id' => $this->dam->id,
                'tag_number' => $this->dam->tag_number,
            ]),
            'sire' => $this->whenLoaded('sire', fn () => $this->sire ? [
                'id' => $this->sire->id,
                'tag_number' => $this->sire->tag_number,
            ] : null),
            'breeding_date' => $this->breeding_date,
            'expected_due_date' => $this->expected_due_date,
            'actual_birth_date' => $this->actual_birth_date,
            'offspring_count' => $this->offspring_count,
            'status' => $this->status,
            'notes' => $this->notes,
            'recorder' => $this->whenLoaded('recorder', fn () => [
                'id' => $this->recorder->id,
                'name' => $this->recorder->name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
