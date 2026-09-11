<?php

namespace App\Http\Controllers\Api\LivestockManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\LivestockManagement\StorePoultryProductionRecordRequest;
use App\Http\Requests\LivestockManagement\UpdatePoultryProductionRecordRequest;
use App\Http\Resources\PoultryProductionRecordResource;
use App\Models\PoultryFlock;
use App\Models\PoultryProductionRecord;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PoultryProductionRecordController extends Controller
{
    public function index(PoultryFlock $poultryFlock): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [PoultryProductionRecord::class, $poultryFlock]);

        return PoultryProductionRecordResource::collection(
            $poultryFlock->productionRecords()->with('recorder')->latest('date')->get()
        );
    }

    public function store(StorePoultryProductionRecordRequest $request, PoultryFlock $poultryFlock): PoultryProductionRecordResource
    {
        $this->authorize('create', [PoultryProductionRecord::class, $poultryFlock]);

        $record = $poultryFlock->productionRecords()->create([
            ...$request->validated(),
            'recorded_by' => $request->user()->id,
        ]);

        return new PoultryProductionRecordResource($record->load('recorder'));
    }

    public function show(PoultryProductionRecord $poultryProductionRecord): PoultryProductionRecordResource
    {
        $this->authorize('view', $poultryProductionRecord);

        return new PoultryProductionRecordResource($poultryProductionRecord->load('recorder'));
    }

    public function update(UpdatePoultryProductionRecordRequest $request, PoultryProductionRecord $poultryProductionRecord): PoultryProductionRecordResource
    {
        $this->authorize('update', $poultryProductionRecord);

        $poultryProductionRecord->update($request->validated());

        return new PoultryProductionRecordResource($poultryProductionRecord->load('recorder'));
    }

    public function destroy(PoultryProductionRecord $poultryProductionRecord): Response
    {
        $this->authorize('delete', $poultryProductionRecord);

        $poultryProductionRecord->delete();

        return response()->noContent();
    }
}
