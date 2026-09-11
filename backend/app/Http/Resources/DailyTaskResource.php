<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class DailyTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'farm_id' => $this->farm_id,
            'assignee' => $this->whenLoaded('assignee', fn () => [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ]),
            'assigner' => $this->whenLoaded('assigner', fn () => [
                'id' => $this->assigner->id,
                'name' => $this->assigner->name,
            ]),
            'title' => $this->title,
            'description' => $this->description,
            'due_date' => $this->due_date,
            'status' => $this->status,
            'completed_at' => $this->completed_at,
            'cost' => $this->cost,
            'gps_lat' => $this->gps_lat,
            'gps_lng' => $this->gps_lng,
            'photo_url' => $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null,
            'inputs_used' => $this->inputs_used,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
