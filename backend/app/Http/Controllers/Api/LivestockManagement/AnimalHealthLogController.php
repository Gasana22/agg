<?php

namespace App\Http\Controllers\Api\LivestockManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\LivestockManagement\StoreAnimalHealthLogRequest;
use App\Http\Requests\LivestockManagement\UpdateAnimalHealthLogRequest;
use App\Http\Resources\AnimalHealthLogResource;
use App\Models\Animal;
use App\Models\AnimalHealthLog;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AnimalHealthLogController extends Controller
{
    public function index(Animal $animal): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [AnimalHealthLog::class, $animal]);

        return AnimalHealthLogResource::collection(
            $animal->healthLogs()->with('recorder')->latest('date')->get()
        );
    }

    public function store(StoreAnimalHealthLogRequest $request, Animal $animal): AnimalHealthLogResource
    {
        $this->authorize('create', [AnimalHealthLog::class, $animal]);

        $log = $animal->healthLogs()->create([
            ...$request->validated(),
            'recorded_by' => $request->user()->id,
        ]);

        return new AnimalHealthLogResource($log->load('recorder'));
    }

    public function show(AnimalHealthLog $animalHealthLog): AnimalHealthLogResource
    {
        $this->authorize('view', $animalHealthLog);

        return new AnimalHealthLogResource($animalHealthLog->load('recorder'));
    }

    public function update(UpdateAnimalHealthLogRequest $request, AnimalHealthLog $animalHealthLog): AnimalHealthLogResource
    {
        $this->authorize('update', $animalHealthLog);

        $animalHealthLog->update($request->validated());

        return new AnimalHealthLogResource($animalHealthLog->load('recorder'));
    }

    public function destroy(AnimalHealthLog $animalHealthLog): Response
    {
        $this->authorize('delete', $animalHealthLog);

        $animalHealthLog->delete();

        return response()->noContent();
    }
}
