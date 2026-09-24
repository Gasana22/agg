<?php

namespace App\Modules\Livestock\Http\Resources;

use App\Modules\Livestock\Domain\Models\Breeding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Breeding */
class BreedingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ref = fn ($a) => $a ? ['id' => $a->id, 'animal_code' => $a->animal_code, 'name' => $a->name] : null;

        return [
            'id' => $this->id,
            'type' => 'animal_breeding',
            'dam' => $this->whenLoaded('dam', fn () => $ref($this->dam)),
            'sire' => $this->whenLoaded('sire', fn () => $ref($this->sire)),
            'sire_note' => $this->sire_note,
            'method' => $this->method->value,
            'served_on' => $this->served_on->toDateString(),
            'expected_due_on' => $this->expected_due_on?->toDateString(),
            'status' => $this->status->value,
            'outcome_on' => $this->outcome_on?->toDateString(),
            'offspring_count' => $this->offspring_count,
            'notes' => $this->notes,
            'version' => $this->version,
        ];
    }
}
