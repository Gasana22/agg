<?php

namespace App\Modules\FarmStructure\Http\Controllers;

use App\Modules\FarmStructure\Application\StructureService;
use App\Modules\FarmStructure\Application\StructureVisibility;
use App\Modules\FarmStructure\Domain\Models\Plot;
use App\Modules\FarmStructure\Http\Resources\StructureNodeResource;
use App\Support\Http\ApiException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Soil information on a plot, recorded by agronomists (docs/04 §3). */
class SoilController
{
    public const TEXTURES = [
        'sand', 'loamy_sand', 'sandy_loam', 'loam', 'silt_loam', 'silt', 'sandy_clay_loam',
        'clay_loam', 'silty_clay_loam', 'sandy_clay', 'silty_clay', 'clay',
    ];

    public function update(Request $request, string $farm, Plot $plot, StructureService $structure, StructureVisibility $visibility): StructureNodeResource
    {
        if (! $visibility->canSee($plot)) {
            throw ApiException::notFound();
        }

        $data = $request->validate([
            'texture' => ['nullable', Rule::in(self::TEXTURES)],
            'ph' => ['nullable', 'numeric', 'between:0,14'],
            'organic_matter_pct' => ['nullable', 'numeric', 'between:0,100'],
            'nitrogen_mg_kg' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'phosphorus_mg_kg' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'potassium_mg_kg' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'ec_ds_m' => ['nullable', 'numeric', 'min:0', 'max:200'],
            'drainage' => ['nullable', Rule::in(['poor', 'moderate', 'good', 'excessive'])],
            'depth_cm' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'tested_on' => ['nullable', 'date', 'before_or_equal:today'],
            'laboratory' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $profile = array_filter($data, fn ($v) => $v !== null);
        foreach (['ph', 'organic_matter_pct', 'nitrogen_mg_kg', 'phosphorus_mg_kg', 'potassium_mg_kg', 'ec_ds_m'] as $key) {
            if (isset($profile[$key])) {
                $profile[$key] = (float) $profile[$key];
            }
        }

        return new StructureNodeResource($structure->recordSoil($plot, $profile));
    }
}
