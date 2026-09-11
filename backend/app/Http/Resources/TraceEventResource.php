<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TraceEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trace_batch_id' => $this->trace_batch_id,
            'type' => $this->type,
            'date' => $this->date,
            'location' => $this->location,
            'notes' => $this->notes,
            'recorder' => $this->whenLoaded('recorder', fn () => [
                'id' => $this->recorder->id,
                'name' => $this->recorder->name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
