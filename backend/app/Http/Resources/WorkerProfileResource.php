<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkerProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'farm_id' => $this->farm_id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'employee_id' => $this->employee_id,
            'hire_date' => $this->hire_date,
            'daily_rate' => $this->daily_rate,
            'supervisor' => $this->whenLoaded('supervisor', fn () => $this->supervisor ? [
                'id' => $this->supervisor->id,
                'name' => $this->supervisor->name,
            ] : null),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
