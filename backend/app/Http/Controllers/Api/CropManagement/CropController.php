<?php

namespace App\Http\Controllers\Api\CropManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\CropManagement\StoreCropRequest;
use App\Http\Requests\CropManagement\UpdateCropRequest;
use App\Http\Resources\CropResource;
use App\Models\Crop;
use App\Models\Farm;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CropController extends Controller
{
    public function index(Farm $farm): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [Crop::class, $farm]);

        return CropResource::collection(
            $farm->crops()->withCount('seasons')->orderBy('name')->get()
        );
    }

    public function store(StoreCropRequest $request, Farm $farm): CropResource
    {
        $this->authorize('create', [Crop::class, $farm]);

        $crop = $farm->crops()->create([...$request->validated(), 'is_active' => true]);

        return new CropResource($crop->loadCount('seasons'));
    }

    public function show(Crop $crop): CropResource
    {
        $this->authorize('view', $crop);

        return new CropResource($crop->loadCount('seasons'));
    }

    public function update(UpdateCropRequest $request, Crop $crop): CropResource
    {
        $this->authorize('update', $crop);

        $crop->update($request->validated());

        return new CropResource($crop->loadCount('seasons'));
    }

    public function destroy(Crop $crop): Response
    {
        $this->authorize('delete', $crop);

        $crop->delete();

        return response()->noContent();
    }
}
