<?php

namespace App\Modules\Crops\Http\Controllers;

use App\Modules\Crops\Application\CropSetup;
use App\Modules\Crops\Domain\Models\Crop;
use App\Modules\Crops\Domain\Models\Season;
use App\Modules\Crops\Http\Resources\CropResource;
use App\Modules\Crops\Http\Resources\SeasonResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The farm's crop list and seasons. */
class SetupController
{
    public function __construct(private readonly CropSetup $setup) {}

    public function crops(Request $request): AnonymousResourceCollection
    {
        $active = $request->boolean('include_inactive') ? null : true;

        return CropResource::collection(Crop::when($active, fn ($q) => $q->where('is_active', true))->orderBy('name')->orderBy('variety')->get());
    }

    public function storeCrop(Request $request): JsonResponse
    {
        $data = $request->validate([
            'global_crop_id' => ['nullable', 'uuid', 'exists:global_crops,id'],
            'global_variety_id' => ['nullable', 'uuid'],
            'name' => ['nullable', 'string', 'max:120'],
            'variety' => ['nullable', 'string', 'max:120'],
            'maturity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'yield_unit' => ['sometimes', 'string', 'exists:units,code'],
        ]);

        return (new CropResource($this->setup->addCrop(array_filter($data, fn ($v) => $v !== null))))->response()->setStatusCode(201);
    }

    public function updateCrop(Request $request, string $farm, Crop $crop): CropResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'variety' => ['sometimes', 'nullable', 'string', 'max:120'],
            'maturity_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'yield_unit' => ['sometimes', 'string', 'exists:units,code'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return new CropResource($this->setup->updateCrop($crop, $data));
    }

    public function seasons(): AnonymousResourceCollection
    {
        return SeasonResource::collection(Season::orderByDesc('starts_on')->get());
    }

    public function storeSeason(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return (new SeasonResource($this->setup->addSeason($data)))->response()->setStatusCode(201);
    }

    public function updateSeason(Request $request, string $farm, Season $season): SeasonResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'starts_on' => ['sometimes', 'date'],
            'ends_on' => ['sometimes', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        return new SeasonResource($this->setup->updateSeason($season, $data));
    }
}
