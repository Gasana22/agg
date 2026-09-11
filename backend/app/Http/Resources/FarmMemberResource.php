<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FarmMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role_on_farm' => $this->whenPivotLoaded('farm_user', fn () => $this->pivot->role_on_farm),
            'joined_at' => $this->whenPivotLoaded('farm_user', fn () => $this->pivot->created_at),
        ];
    }
}
