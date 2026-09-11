<?php

namespace App\Http\Controllers\Api\LivestockManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\LivestockManagement\StorePoultryFlockRequest;
use App\Http\Requests\LivestockManagement\UpdatePoultryFlockRequest;
use App\Http\Resources\PoultryFlockResource;
use App\Models\Farm;
use App\Models\PoultryFlock;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PoultryFlockController extends Controller
{
    public function index(Farm $farm): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [PoultryFlock::class, $farm]);

        return PoultryFlockResource::collection(
            $farm->poultryFlocks()->orderBy('flock_code')->get()
        );
    }

    public function store(StorePoultryFlockRequest $request, Farm $farm): PoultryFlockResource
    {
        $this->authorize('create', [PoultryFlock::class, $farm]);

        $flock = $farm->poultryFlocks()->create([
            ...$request->validated(),
            'status' => 'active',
        ]);

        return new PoultryFlockResource($flock);
    }

    public function show(PoultryFlock $poultryFlock): PoultryFlockResource
    {
        $this->authorize('view', $poultryFlock);

        return new PoultryFlockResource($poultryFlock);
    }

    public function update(UpdatePoultryFlockRequest $request, PoultryFlock $poultryFlock): PoultryFlockResource
    {
        $this->authorize('update', $poultryFlock);

        $poultryFlock->update($request->validated());

        return new PoultryFlockResource($poultryFlock);
    }

    public function destroy(PoultryFlock $poultryFlock): Response
    {
        $this->authorize('delete', $poultryFlock);

        $poultryFlock->delete();

        return response()->noContent();
    }
}
