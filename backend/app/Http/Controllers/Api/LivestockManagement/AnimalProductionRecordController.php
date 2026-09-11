<?php

namespace App\Http\Controllers\Api\LivestockManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\LivestockManagement\StoreAnimalProductionRecordRequest;
use App\Http\Requests\LivestockManagement\UpdateAnimalProductionRecordRequest;
use App\Http\Resources\AnimalProductionRecordResource;
use App\Models\Animal;
use App\Models\AnimalProductionRecord;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AnimalProductionRecordController extends Controller
{
    public function index(Animal $animal): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [AnimalProductionRecord::class, $animal]);

        return AnimalProductionRecordResource::collection(
            $animal->productionRecords()->with('recorder')->latest('date')->get()
        );
    }

    public function store(StoreAnimalProductionRecordRequest $request, Animal $animal): AnimalProductionRecordResource
    {
        $this->authorize('create', [AnimalProductionRecord::class, $animal]);

        $record = $animal->productionRecords()->create([
            ...$request->validated(),
            'recorded_by' => $request->user()->id,
        ]);

        return new AnimalProductionRecordResource($record->load('recorder'));
    }

    public function show(AnimalProductionRecord $animalProductionRecord): AnimalProductionRecordResource
    {
        $this->authorize('view', $animalProductionRecord);

        return new AnimalProductionRecordResource($animalProductionRecord->load('recorder'));
    }

    public function update(UpdateAnimalProductionRecordRequest $request, AnimalProductionRecord $animalProductionRecord): AnimalProductionRecordResource
    {
        $this->authorize('update', $animalProductionRecord);

        $animalProductionRecord->update($request->validated());

        return new AnimalProductionRecordResource($animalProductionRecord->load('recorder'));
    }

    public function destroy(AnimalProductionRecord $animalProductionRecord): Response
    {
        $this->authorize('delete', $animalProductionRecord);

        $animalProductionRecord->delete();

        return response()->noContent();
    }
}
