<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TraceBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'farm_id' => $this->farm_id,
            'code' => $this->code,
            'source_type' => $this->traceable_type,
            'source_id' => $this->traceable_id,
            'product_name' => $this->product_name,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'status' => $this->status,
            'creator' => $this->whenLoaded('creator', fn () => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'events' => TraceEventResource::collection($this->whenLoaded('events')),
            'trace_url' => config('app.frontend_url').'/trace/'.$this->code,
            'created_at' => $this->created_at,
        ];
    }
}
