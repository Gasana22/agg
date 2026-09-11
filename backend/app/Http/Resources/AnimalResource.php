<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnimalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'farm_id' => $this->farm_id,
            'tag_number' => $this->tag_number,
            'name' => $this->name,
            'species' => $this->species,
            'breed' => $this->breed,
            'sex' => $this->sex,
            'birth_date' => $this->birth_date,
            'dam' => $this->whenLoaded('dam', fn () => $this->dam ? [
                'id' => $this->dam->id,
                'tag_number' => $this->dam->tag_number,
            ] : null),
            'sire' => $this->whenLoaded('sire', fn () => $this->sire ? [
                'id' => $this->sire->id,
                'tag_number' => $this->sire->tag_number,
            ] : null),
            'source' => $this->source,
            'acquired_date' => $this->acquired_date,
            'status' => $this->status,
            'death_date' => $this->death_date,
            'cause_of_death' => $this->cause_of_death,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
