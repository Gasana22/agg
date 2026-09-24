<?php

namespace App\Modules\Livestock\Http\Resources;

use App\Modules\Livestock\Domain\Models\AnimalGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/** @mixin AnimalGroup */
class GroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'animal_group',
            'code' => $this->code,
            'name' => $this->name,
            'species' => ['id' => $this->species_id, 'name' => DB::table('global_animal_species')->where('id', $this->species_id)->value('name')],
            'purpose' => $this->purpose->value,
            'location' => $this->whenLoaded('location', fn () => $this->location ? ['id' => $this->location->id, 'code' => $this->location->code, 'name' => $this->location->name] : null),
            'flock_size' => $this->flock_size,
            'head_count' => $this->whenHas('active_animals_count', fn () => (int) $this->active_animals_count),
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'version' => $this->version,
        ];
    }
}
