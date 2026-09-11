<?php

namespace App\Http\Controllers\Api\CropManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\CropManagement\StoreCropSeasonRequest;
use App\Http\Requests\CropManagement\UpdateCropSeasonRequest;
use App\Http\Resources\CropSeasonResource;
use App\Models\CropSeason;
use App\Models\Farm;
use App\Models\Plot;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class CropSeasonController extends Controller
{
    public function index(Farm $farm): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [CropSeason::class, $farm]);

        return CropSeasonResource::collection(
            $farm->cropSeasons()->with('crop')->withCount(['activities', 'harvests'])->latest()->get()
        );
    }

    public function store(StoreCropSeasonRequest $request, Farm $farm): CropSeasonResource
    {
        $this->authorize('create', [CropSeason::class, $farm]);

        $cropId = $request->validated('crop_id');

        if (! $farm->crops()->where('id', $cropId)->exists()) {
            throw ValidationException::withMessages([
                'crop_id' => 'This crop does not belong to this farm.',
            ]);
        }

        if ($plotId = $request->validated('plot_id')) {
            $plot = Plot::with('section.block')->find($plotId);
            if (! $plot || $plot->section->block->farm_id !== $farm->id) {
                throw ValidationException::withMessages([
                    'plot_id' => 'This plot does not belong to this farm.',
                ]);
            }
        }

        $season = $farm->cropSeasons()->create([
            ...$request->validated(),
            'status' => 'planning',
        ]);

        return new CropSeasonResource($season->load('crop'));
    }

    public function show(CropSeason $cropSeason): CropSeasonResource
    {
        $this->authorize('view', $cropSeason);

        return new CropSeasonResource($cropSeason->load('crop')->loadCount(['activities', 'harvests']));
    }

    public function update(UpdateCropSeasonRequest $request, CropSeason $cropSeason): CropSeasonResource
    {
        $this->authorize('update', $cropSeason);

        if ($plotId = $request->validated('plot_id')) {
            $plot = Plot::with('section.block')->find($plotId);
            if (! $plot || $plot->section->block->farm_id !== $cropSeason->farm_id) {
                throw ValidationException::withMessages([
                    'plot_id' => 'This plot does not belong to this farm.',
                ]);
            }
        }

        $cropSeason->update($request->validated());

        return new CropSeasonResource($cropSeason->load('crop'));
    }

    public function destroy(CropSeason $cropSeason): Response
    {
        $this->authorize('delete', $cropSeason);

        $cropSeason->delete();

        return response()->noContent();
    }
}
