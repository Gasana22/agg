<?php

namespace App\Modules\Livestock\Http\Resources;

use App\Modules\Livestock\Domain\Models\Animal;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/** @mixin Animal */
class AnimalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $today = CarbonImmutable::today();
        $ref = fn (?Animal $a) => $a ? ['id' => $a->id, 'animal_code' => $a->animal_code, 'name' => $a->name] : null;

        return [
            'id' => $this->id,
            'type' => 'animal',
            'animal_code' => $this->animal_code,
            'tag_number' => $this->tag_number,
            'rfid' => $this->rfid,
            'name' => $this->name,
            'label' => $this->label(),
            'species' => ['id' => $this->species_id, 'name' => DB::table('global_animal_species')->where('id', $this->species_id)->value('name')],
            'breed' => $this->breed_id ? ['id' => $this->breed_id, 'name' => DB::table('global_animal_breeds')->where('id', $this->breed_id)->value('name')] : null,
            'breed_note' => $this->breed_note,
            'sex' => $this->sex->value,
            'birth_date' => $this->birth_date?->toDateString(),
            'birth_date_estimated' => $this->birth_date_estimated,
            'age_months' => $this->birth_date ? (int) $this->birth_date->diffInMonths($today) : null,
            'origin' => $this->origin->value,
            'acquired_on' => $this->acquired_on?->toDateString(),
            'dam' => $this->whenLoaded('dam', fn () => $ref($this->dam)),
            'sire' => $this->whenLoaded('sire', fn () => $ref($this->sire)),
            'parentage_note' => $this->parentage_note,
            'group' => $this->whenLoaded('group', fn () => $this->group ? ['id' => $this->group->id, 'code' => $this->group->code, 'name' => $this->group->name] : null),
            'location' => $this->whenLoaded('location', fn () => $this->location ? ['id' => $this->location->id, 'code' => $this->location->code, 'name' => $this->location->name] : null),
            'status' => $this->status->value,
            'exited_on' => $this->exited_on?->toDateString(),
            'exit_reason' => $this->exit_reason,
            'last_weight_kg' => $this->last_weight_kg === null ? null : (float) $this->last_weight_kg,
            'last_weighed_on' => $this->last_weighed_on?->toDateString(),
            'meat_withdrawal_until' => $this->meat_withdrawal_until && $this->meat_withdrawal_until->gte($today) ? $this->meat_withdrawal_until->toDateString() : null,
            'milk_withdrawal_until' => $this->milk_withdrawal_until && $this->milk_withdrawal_until->gte($today) ? $this->milk_withdrawal_until->toDateString() : null,
            'pregnancy' => $this->whenHas('pregnancy_due_on', fn () => $this->pregnancy_due_on ? ['expected_due_on' => substr((string) $this->pregnancy_due_on, 0, 10)] : null),
            'batch' => $this->whenLoaded('batch', fn () => $this->batch ? ['id' => $this->batch->id, 'batch_code' => $this->batch->batch_code] : null),
            'notes' => $this->notes,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
