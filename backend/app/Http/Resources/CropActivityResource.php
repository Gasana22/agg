<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CropActivityResource extends JsonResource
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
            'cost' => $this->cost,
            'gps_lat' => $this->gps_lat,
            'gps_lng' => $this->gps_lng,
            'photo_url' => $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null,
            'notes' => $this->notes,
            'performer' => $this->whenLoaded('performer', fn () => [
                'id' => $this->performer->id,
                'name' => $this->performer->name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
