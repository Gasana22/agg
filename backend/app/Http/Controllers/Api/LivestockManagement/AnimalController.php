<?php

namespace App\Http\Controllers\Api\LivestockManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\LivestockManagement\StoreAnimalRequest;
use App\Http\Requests\LivestockManagement\UpdateAnimalRequest;
use App\Http\Resources\AnimalResource;
use App\Models\Animal;
use App\Models\Farm;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class AnimalController extends Controller
{
    public function index(Farm $farm): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [Animal::class, $farm]);

        return AnimalResource::collection(
            $farm->animals()->with(['dam', 'sire'])->orderBy('tag_number')->get()
        );
    }

    public function store(StoreAnimalRequest $request, Farm $farm): AnimalResource
    {
        $this->authorize('create', [Animal::class, $farm]);

        $this->assertParentsBelongToFarm($request->validated('dam_id'), $request->validated('sire_id'), $farm);

        $animal = $farm->animals()->create([
            ...$request->validated(),
            'status' => 'active',
        ]);

        return new AnimalResource($animal->load(['dam', 'sire']));
    }

    public function show(Animal $animal): AnimalResource
    {
        $this->authorize('view', $animal);

        return new AnimalResource($animal->load(['dam', 'sire']));
    }

    public function update(UpdateAnimalRequest $request, Animal $animal): AnimalResource
    {
        $this->authorize('update', $animal);

        $this->assertParentsBelongToFarm(
            $request->validated('dam_id'),
            $request->validated('sire_id'),
            $animal->farm
        );

        $animal->update($request->validated());

        return new AnimalResource($animal->load(['dam', 'sire']));
    }

    public function destroy(Animal $animal): Response
    {
        $this->authorize('delete', $animal);

        $animal->delete();

        return response()->noContent();
    }

    private function assertParentsBelongToFarm(?int $damId, ?int $sireId, Farm $farm): void
    {
        if ($damId && ! $farm->animals()->where('id', $damId)->exists()) {
            throw ValidationException::withMessages(['dam_id' => 'This animal does not belong to this farm.']);
        }

        if ($sireId && ! $farm->animals()->where('id', $sireId)->exists()) {
            throw ValidationException::withMessages(['sire_id' => 'This animal does not belong to this farm.']);
        }
    }
}
