<?php

namespace App\Http\Controllers\Api\CropManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\CropManagement\StoreCropHarvestRequest;
use App\Http\Requests\CropManagement\UpdateCropHarvestRequest;
use App\Http\Resources\CropHarvestResource;
use App\Models\CropHarvest;
use App\Models\CropSeason;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CropHarvestController extends Controller
{
    public function index(CropSeason $cropSeason): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [CropHarvest::class, $cropSeason]);

        return CropHarvestResource::collection(
            $cropSeason->harvests()->with('recorder')->withCount('sales')->latest('harvest_date')->get()
        );
    }

    public function store(StoreCropHarvestRequest $request, CropSeason $cropSeason): CropHarvestResource
    {
        $this->authorize('create', [CropHarvest::class, $cropSeason]);

        $harvest = $cropSeason->harvests()->create([
            ...$request->validated(),
            'recorded_by' => $request->user()->id,
        ]);

        return new CropHarvestResource($harvest->load('recorder'));
    }

    public function show(CropHarvest $cropHarvest): CropHarvestResource
    {
        $this->authorize('view', $cropHarvest);

        return new CropHarvestResource($cropHarvest->load('recorder')->loadCount('sales'));
    }

    public function update(UpdateCropHarvestRequest $request, CropHarvest $cropHarvest): CropHarvestResource
    {
        $this->authorize('update', $cropHarvest);

        $cropHarvest->update($request->validated());

        return new CropHarvestResource($cropHarvest->load('recorder'));
    }

    public function destroy(CropHarvest $cropHarvest): Response
    {
        $this->authorize('delete', $cropHarvest);

        $cropHarvest->delete();

        return response()->noContent();
    }
}
