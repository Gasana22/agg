<?php

namespace App\Http\Controllers\Api\CropManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\CropManagement\StoreCropMonitoringLogRequest;
use App\Http\Requests\CropManagement\UpdateCropMonitoringLogRequest;
use App\Http\Resources\CropMonitoringLogResource;
use App\Models\CropMonitoringLog;
use App\Models\CropSeason;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CropMonitoringLogController extends Controller
{
    public function index(CropSeason $cropSeason): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [CropMonitoringLog::class, $cropSeason]);

        return CropMonitoringLogResource::collection(
            $cropSeason->monitoringLogs()->with('reporter')->latest('date')->get()
        );
    }

    public function store(StoreCropMonitoringLogRequest $request, CropSeason $cropSeason): CropMonitoringLogResource
    {
        $this->authorize('create', [CropMonitoringLog::class, $cropSeason]);

        $photoPath = $request->hasFile('photo')
            ? $request->file('photo')->store('crop-monitoring-photos', 'public')
            : null;

        $log = $cropSeason->monitoringLogs()->create([
            ...$request->validated(),
            'photo_path' => $photoPath,
            'reported_by' => $request->user()->id,
        ]);

        return new CropMonitoringLogResource($log->load('reporter'));
    }

    public function show(CropMonitoringLog $cropMonitoringLog): CropMonitoringLogResource
    {
        $this->authorize('view', $cropMonitoringLog);

        return new CropMonitoringLogResource($cropMonitoringLog->load('reporter'));
    }

    public function update(UpdateCropMonitoringLogRequest $request, CropMonitoringLog $cropMonitoringLog): CropMonitoringLogResource
    {
        $this->authorize('update', $cropMonitoringLog);

        $data = $request->validated();

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('crop-monitoring-photos', 'public');
        }

        $cropMonitoringLog->update($data);

        return new CropMonitoringLogResource($cropMonitoringLog->load('reporter'));
    }

    public function destroy(CropMonitoringLog $cropMonitoringLog): Response
    {
        $this->authorize('delete', $cropMonitoringLog);

        $cropMonitoringLog->delete();

        return response()->noContent();
    }
}
