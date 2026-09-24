<?php

namespace App\Modules\Crops\Http\Resources;

use App\Modules\Crops\Domain\Models\CropObservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CropObservation */
class ObservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'crop_observation',
            'cycle_id' => $this->cycle_id,
            'cycle' => $this->whenLoaded('cycle', fn () => ['id' => $this->cycle->id, 'code' => $this->cycle->code]),
            'kind' => $this->kind->value,
            'severity' => $this->severity->value,
            'title' => $this->title,
            'description' => $this->description,
            'affected_pct' => $this->affected_pct === null ? null : (float) $this->affected_pct,
            'observed_at' => $this->observed_at->toIso8601ZuluString(),
            'latitude' => $this->latitude === null ? null : (float) $this->latitude,
            'longitude' => $this->longitude === null ? null : (float) $this->longitude,
            'status' => $this->status->value,
            'resolved_at' => $this->resolved_at?->toIso8601ZuluString(),
            'resolution_note' => $this->resolution_note,
            'treatments' => $this->whenHas('treatments_count', fn () => (int) $this->treatments_count),
            'recorded_by' => $this->whenLoaded('recorder', fn () => $this->recorder ? ['id' => $this->recorder->id, 'name' => $this->recorder->name] : null),
            'version' => $this->version,
        ];
    }
}
