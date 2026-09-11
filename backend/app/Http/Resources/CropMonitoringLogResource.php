<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CropMonitoringLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'crop_season_id' => $this->crop_season_id,
            'type' => $this->type,
            'date' => $this->date,
            'description' => $this->description,
            'severity' => $this->severity,
            'photo_url' => $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null,
            'reporter' => $this->whenLoaded('reporter', fn () => [
                'id' => $this->reporter->id,
                'name' => $this->reporter->name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
