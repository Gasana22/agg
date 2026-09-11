<?php

namespace App\Http\Controllers\Api\FarmStructure;

use App\Http\Controllers\Controller;
use App\Http\Requests\FarmStructure\StoreFarmRequest;
use App\Http\Requests\FarmStructure\UpdateFarmRequest;
use App\Http\Resources\FarmResource;
use App\Models\Farm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class FarmController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Farm::class);

        $user = $request->user();

        if ($user->isSystemAdministrator()) {
            $farms = Farm::withCount('blocks')->orderBy('name')->get();
        } else {
            $farms = $user->farms()->withCount('blocks')->orderBy('name')->get();
        }

        return FarmResource::collection($farms);
    }

    public function store(StoreFarmRequest $request): FarmResource
    {
        $this->authorize('create', Farm::class);

        $farm = Farm::create([
            ...$request->validated(),
            'owner_id' => $request->user()->id,
            'is_active' => true,
        ]);

        return new FarmResource($farm->loadCount('blocks'));
    }

    public function show(Farm $farm): FarmResource
    {
        $this->authorize('view', $farm);

        return new FarmResource($farm->load('owner')->loadCount('blocks'));
    }

    public function update(UpdateFarmRequest $request, Farm $farm): FarmResource
    {
        $this->authorize('update', $farm);

        $farm->update($request->validated());

        return new FarmResource($farm->loadCount('blocks'));
    }

    public function destroy(Farm $farm): Response
    {
        $this->authorize('delete', $farm);

        $farm->delete();

        return response()->noContent();
    }
}
