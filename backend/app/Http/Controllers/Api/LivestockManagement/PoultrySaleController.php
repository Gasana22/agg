<?php

namespace App\Http\Controllers\Api\LivestockManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\LivestockManagement\StorePoultrySaleRequest;
use App\Http\Requests\LivestockManagement\UpdatePoultrySaleRequest;
use App\Http\Resources\PoultrySaleResource;
use App\Models\PoultryFlock;
use App\Models\PoultrySale;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PoultrySaleController extends Controller
{
    public function index(PoultryFlock $poultryFlock): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [PoultrySale::class, $poultryFlock]);

        return PoultrySaleResource::collection(
            $poultryFlock->sales()->with('recorder')->latest('sale_date')->get()
        );
    }

    public function store(StorePoultrySaleRequest $request, PoultryFlock $poultryFlock): PoultrySaleResource
    {
        $this->authorize('create', [PoultrySale::class, $poultryFlock]);

        $sale = $poultryFlock->sales()->create([
            ...$request->validated(),
            'recorded_by' => $request->user()->id,
        ]);

        return new PoultrySaleResource($sale->load('recorder'));
    }

    public function show(PoultrySale $poultrySale): PoultrySaleResource
    {
        $this->authorize('view', $poultrySale);

        return new PoultrySaleResource($poultrySale->load('recorder'));
    }

    public function update(UpdatePoultrySaleRequest $request, PoultrySale $poultrySale): PoultrySaleResource
    {
        $this->authorize('update', $poultrySale);

        $poultrySale->update($request->validated());

        return new PoultrySaleResource($poultrySale->load('recorder'));
    }

    public function destroy(PoultrySale $poultrySale): Response
    {
        $this->authorize('delete', $poultrySale);

        $poultrySale->delete();

        return response()->noContent();
    }
}
