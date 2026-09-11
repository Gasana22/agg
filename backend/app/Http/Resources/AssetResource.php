<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'farm_id' => $this->farm_id,
            'name' => $this->name,
            'category' => $this->category,
            'serial_number' => $this->serial_number,
            'purchase_date' => $this->purchase_date,
            'purchase_cost' => $this->purchase_cost,
            'status' => $this->status,
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ] : null),
            'notes' => $this->notes,
            'maintenance_logs' => AssetMaintenanceLogResource::collection($this->whenLoaded('maintenanceLogs')),
            'created_at' => $this->created_at,
        ];
    }
}
