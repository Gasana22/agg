<?php

namespace App\Http\Controllers\Api\LivestockManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\LivestockManagement\StorePoultryMortalityLogRequest;
use App\Http\Requests\LivestockManagement\UpdatePoultryMortalityLogRequest;
use App\Http\Resources\PoultryMortalityLogResource;
use App\Models\PoultryFlock;
use App\Models\PoultryMortalityLog;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PoultryMortalityLogController extends Controller
{
    public function index(PoultryFlock $poultryFlock): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [PoultryMortalityLog::class, $poultryFlock]);

        return PoultryMortalityLogResource::collection(
            $poultryFlock->mortalityLogs()->with('recorder')->latest('date')->get()
        );
    }

    public function store(StorePoultryMortalityLogRequest $request, PoultryFlock $poultryFlock): PoultryMortalityLogResource
    {
        $this->authorize('create', [PoultryMortalityLog::class, $poultryFlock]);

        $log = $poultryFlock->mortalityLogs()->create([
            ...$request->validated(),
            'recorded_by' => $request->user()->id,
        ]);

        return new PoultryMortalityLogResource($log->load('recorder'));
    }

    public function show(PoultryMortalityLog $poultryMortalityLog): PoultryMortalityLogResource
    {
        $this->authorize('view', $poultryMortalityLog);

        return new PoultryMortalityLogResource($poultryMortalityLog->load('recorder'));
    }

    public function update(UpdatePoultryMortalityLogRequest $request, PoultryMortalityLog $poultryMortalityLog): PoultryMortalityLogResource
    {
        $this->authorize('update', $poultryMortalityLog);

        $poultryMortalityLog->update($request->validated());

        return new PoultryMortalityLogResource($poultryMortalityLog->load('recorder'));
    }

    public function destroy(PoultryMortalityLog $poultryMortalityLog): Response
    {
        $this->authorize('delete', $poultryMortalityLog);

        $poultryMortalityLog->delete();

        return response()->noContent();
    }
}
