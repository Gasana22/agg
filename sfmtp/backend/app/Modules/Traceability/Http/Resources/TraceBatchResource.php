<?php

namespace App\Modules\Traceability\Http\Resources;

use App\Modules\Traceability\Application\BatchOperations;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TraceBatch */
class TraceBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'trace_batch',
            'batch_code' => $this->batch_code,
            'kind' => $this->kind->value,
            'name' => $this->name,
            'status' => $this->status->value,
            'quantity' => $this->quantity === null ? null : ['value' => $this->quantity, 'unit' => $this->unit],
            // What splits, merges, processing, packing and shipments have not taken yet.
            'available' => $this->quantity === null ? null : ['value' => app(BatchOperations::class)->available($this->resource), 'unit' => $this->unit],
            'origin_plot_id' => $this->origin_plot_id,
            'source' => $this->source_type ? ['type' => $this->source_type, 'id' => $this->source_id] : null,
            'version' => $this->version,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
