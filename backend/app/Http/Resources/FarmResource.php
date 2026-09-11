<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FarmResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'district' => $this->district,
            'village' => $this->village,
            'gps_lat' => $this->gps_lat,
            'gps_lng' => $this->gps_lng,
            'boundary' => $this->boundary,
            'is_active' => $this->is_active,
            'owner' => $this->whenLoaded('owner', fn () => [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
            ]),
            'blocks_count' => $this->whenCounted('blocks'),
            // Present only when this farm was loaded through the current
            // user's own farms() relation (e.g. GET /farms for a non-admin).
            'my_role' => $this->whenPivotLoaded('farm_user', fn () => $this->pivot->role_on_farm),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
