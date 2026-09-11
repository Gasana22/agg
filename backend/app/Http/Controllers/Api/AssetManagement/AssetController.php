<?php

namespace App\Http\Controllers\Api\AssetManagement;

use App\Enums\AssetStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssetManagement\StoreAssetRequest;
use App\Http\Requests\AssetManagement\UpdateAssetRequest;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use App\Models\Farm;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AssetController extends Controller
{
    public function index(Farm $farm): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [Asset::class, $farm]);

        return AssetResource::collection(
            $farm->assets()->with('assignee')->orderBy('name')->get()
        );
    }

    public function store(StoreAssetRequest $request, Farm $farm): AssetResource
    {
        $this->authorize('create', [Asset::class, $farm]);

        $asset = $farm->assets()->create([
            ...$request->validated(),
            'status' => AssetStatus::Active->value,
        ]);

        return new AssetResource($asset->load('assignee'));
    }

    public function show(Asset $asset): AssetResource
    {
        $this->authorize('view', $asset);

        return new AssetResource($asset->load(['assignee', 'maintenanceLogs.recorder']));
    }

    public function update(UpdateAssetRequest $request, Asset $asset): AssetResource
    {
        $this->authorize('update', $asset);

        $asset->update($request->validated());

        return new AssetResource($asset->load('assignee'));
    }

    public function destroy(Asset $asset): Response
    {
        $this->authorize('delete', $asset);

        $asset->delete();

        return response()->noContent();
    }
}
