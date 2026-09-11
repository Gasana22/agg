<?php

namespace App\Http\Controllers\Api\LivestockManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\LivestockManagement\StoreBreedingRecordRequest;
use App\Http\Requests\LivestockManagement\UpdateBreedingRecordRequest;
use App\Http\Resources\BreedingRecordResource;
use App\Models\BreedingRecord;
use App\Models\Farm;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class BreedingRecordController extends Controller
{
    public function index(Farm $farm): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [BreedingRecord::class, $farm]);

        return BreedingRecordResource::collection(
            $farm->breedingRecords()->with(['dam', 'sire', 'recorder'])->latest('breeding_date')->get()
        );
    }

    public function store(StoreBreedingRecordRequest $request, Farm $farm): BreedingRecordResource
    {
        $this->authorize('create', [BreedingRecord::class, $farm]);

        $damId = $request->validated('dam_id');
        $sireId = $request->validated('sire_id');

        if (! $farm->animals()->where('id', $damId)->exists()) {
            throw ValidationException::withMessages(['dam_id' => 'This animal does not belong to this farm.']);
        }

        if ($sireId && ! $farm->animals()->where('id', $sireId)->exists()) {
            throw ValidationException::withMessages(['sire_id' => 'This animal does not belong to this farm.']);
        }

        $record = $farm->breedingRecords()->create([
            ...$request->validated(),
            'status' => 'bred',
            'recorded_by' => $request->user()->id,
        ]);

        return new BreedingRecordResource($record->load(['dam', 'sire', 'recorder']));
    }

    public function show(BreedingRecord $breedingRecord): BreedingRecordResource
    {
        $this->authorize('view', $breedingRecord);

        return new BreedingRecordResource($breedingRecord->load(['dam', 'sire', 'recorder']));
    }

    public function update(UpdateBreedingRecordRequest $request, BreedingRecord $breedingRecord): BreedingRecordResource
    {
        $this->authorize('update', $breedingRecord);

        $breedingRecord->update($request->validated());

        return new BreedingRecordResource($breedingRecord->load(['dam', 'sire', 'recorder']));
    }

    public function destroy(BreedingRecord $breedingRecord): Response
    {
        $this->authorize('delete', $breedingRecord);

        $breedingRecord->delete();

        return response()->noContent();
    }
}
