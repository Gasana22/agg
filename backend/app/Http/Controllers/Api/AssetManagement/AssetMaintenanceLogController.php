<?php

namespace App\Http\Controllers\Api\AssetManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssetManagement\StoreAssetMaintenanceLogRequest;
use App\Http\Requests\AssetManagement\UpdateAssetMaintenanceLogRequest;
use App\Http\Resources\AssetMaintenanceLogResource;
use App\Models\Asset;
use App\Models\AssetMaintenanceLog;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AssetMaintenanceLogController extends Controller
{
    public function index(Asset $asset): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [AssetMaintenanceLog::class, $asset]);

        return AssetMaintenanceLogResource::collection(
            $asset->maintenanceLogs()->with('recorder')->latest('date')->get()
        );
    }

    /**
     * A log entry records maintenance that happened, like AnimalHealthLog
     * for animals — it doesn't itself change the asset's status. Taking an
     * asset in and out of service (active/under_maintenance/retired) is a
     * separate, explicit decision made via the asset's own update endpoint.
     */
    public function store(StoreAssetMaintenanceLogRequest $request, Asset $asset): AssetMaintenanceLogResource
    {
        $this->authorize('create', [AssetMaintenanceLog::class, $asset]);

        $log = $asset->maintenanceLogs()->create([
            ...$request->validated(),
            'recorded_by' => $request->user()->id,
        ]);

        return new AssetMaintenanceLogResource($log->load('recorder'));
    }

    public function show(AssetMaintenanceLog $assetMaintenanceLog): AssetMaintenanceLogResource
    {
        $this->authorize('view', $assetMaintenanceLog);

        return new AssetMaintenanceLogResource($assetMaintenanceLog->load('recorder'));
    }

    public function update(UpdateAssetMaintenanceLogRequest $request, AssetMaintenanceLog $assetMaintenanceLog): AssetMaintenanceLogResource
    {
        $this->authorize('update', $assetMaintenanceLog);

        $assetMaintenanceLog->update($request->validated());

        return new AssetMaintenanceLogResource($assetMaintenanceLog->load('recorder'));
    }

    public function destroy(AssetMaintenanceLog $assetMaintenanceLog): Response
    {
        $this->authorize('delete', $assetMaintenanceLog);

        $assetMaintenanceLog->delete();

        return response()->noContent();
    }
}
