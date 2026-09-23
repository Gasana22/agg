<?php

namespace App\Modules\FarmStructure\Http\Resources;

use App\Modules\FarmStructure\Domain\Models\Location;
use App\Modules\FarmStructure\Domain\Models\Plot;
use App\Modules\FarmStructure\Domain\Models\Section;
use App\Modules\FarmStructure\Domain\Models\StructureNode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StructureNode */
class StructureNodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $node = $this->resource;

        $data = [
            'id' => $node->id,
            'type' => 'farm_'.$node->nodeType(),
            'code' => $node->code,
            'name' => $node->name,
            'description' => $node->description,
        ];

        if ($node instanceof Section) {
            $data['block_id'] = $node->block_id;
        }
        if ($node instanceof Plot) {
            $data += [
                'section_id' => $node->section_id,
                'land_use' => $node->land_use->value,
                'irrigation' => $node->irrigation->value,
                'soil_profile' => $node->soil_profile,
                'soil_updated_at' => $node->soil_updated_at?->toIso8601ZuluString(),
            ];
        }
        if ($node instanceof Location) {
            $data += [
                'kind' => $node->kind->value,
                'plot_id' => $node->plot_id,
                'latitude' => $node->latitude === null ? null : (float) $node->latitude,
                'longitude' => $node->longitude === null ? null : (float) $node->longitude,
            ];
        }

        return $data + [
            'boundary' => $node->boundary,
            'area_ha' => $node->area_ha === null ? null : (float) $node->area_ha,
            'declared_area_ha' => $node->declared_area_ha === null ? null : (float) $node->declared_area_ha,
            'effective_area_ha' => $node->effectiveAreaHa(),
            'centroid' => $node->centroid_lat === null ? null : ['lat' => (float) $node->centroid_lat, 'lng' => (float) $node->centroid_lng],
            'version' => $node->version,
            'created_at' => $node->created_at?->toIso8601ZuluString(),
            'updated_at' => $node->updated_at?->toIso8601ZuluString(),
        ];
    }
}
