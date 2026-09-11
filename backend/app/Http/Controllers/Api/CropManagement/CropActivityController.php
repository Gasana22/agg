<?php

namespace App\Http\Controllers\Api\CropManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\CropManagement\StoreCropActivityRequest;
use App\Http\Requests\CropManagement\UpdateCropActivityRequest;
use App\Http\Resources\CropActivityResource;
use App\Models\CropActivity;
use App\Models\CropSeason;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CropActivityController extends Controller
{
    public function index(CropSeason $cropSeason): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [CropActivity::class, $cropSeason]);

        return CropActivityResource::collection(
            $cropSeason->activities()->with('performer')->latest('date')->get()
        );
    }

    public function store(StoreCropActivityRequest $request, CropSeason $cropSeason): CropActivityResource
    {
        $this->authorize('create', [CropActivity::class, $cropSeason]);

        $photoPath = $request->hasFile('photo')
            ? $request->file('photo')->store('crop-activity-photos', 'public')
            : null;

        $activity = $cropSeason->activities()->create([
            ...$request->validated(),
            'photo_path' => $photoPath,
            'performed_by' => $request->user()->id,
        ]);

        return new CropActivityResource($activity->load('performer'));
    }

    public function show(CropActivity $cropActivity): CropActivityResource
    {
        $this->authorize('view', $cropActivity);

        return new CropActivityResource($cropActivity->load('performer'));
    }

    public function update(UpdateCropActivityRequest $request, CropActivity $cropActivity): CropActivityResource
    {
        $this->authorize('update', $cropActivity);

        $data = $request->validated();

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('crop-activity-photos', 'public');
        }

        $cropActivity->update($data);

        return new CropActivityResource($cropActivity->load('performer'));
    }

    public function destroy(CropActivity $cropActivity): Response
    {
        $this->authorize('delete', $cropActivity);

        $cropActivity->delete();

        return response()->noContent();
    }
}
