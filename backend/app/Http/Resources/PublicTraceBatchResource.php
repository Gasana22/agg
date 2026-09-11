<?php

namespace App\Http\Resources;

use App\Models\AnimalProductionRecord;
use App\Models\CropHarvest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The consumer-facing view returned by the public trace lookup. Deliberately
 * excludes internal ids and staff names (who recorded what) — a shopper
 * scanning a QR code needs provenance, not the farm's staff roster.
 */
class PublicTraceBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'product_name' => $this->product_name,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'status' => $this->status,
            'farm' => $this->whenLoaded('farm', fn () => [
                'name' => $this->farm->name,
                'district' => $this->farm->district,
                'village' => $this->farm->village,
            ]),
            'origin' => $this->whenLoaded('traceable', fn () => $this->describeOrigin()),
            'timeline' => TraceEventResource::collection(
                $this->whenLoaded('events', fn () => $this->events->map->makeHidden('recorder'))
            ),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeOrigin(): array
    {
        return match ($this->traceable_type) {
            CropHarvest::class => [
                'type' => 'crop_harvest',
                'harvest_date' => $this->traceable->harvest_date,
                'quality_grade' => $this->traceable->quality_grade,
            ],
            AnimalProductionRecord::class => [
                'type' => 'animal_production_record',
                'product_type' => $this->traceable->product_type,
                'collection_date' => $this->traceable->date,
            ],
            default => ['type' => null],
        };
    }
}
