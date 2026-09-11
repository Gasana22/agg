<?php

namespace App\Http\Controllers\Api\CropManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\CropManagement\StoreCropSaleRequest;
use App\Http\Requests\CropManagement\UpdateCropSaleRequest;
use App\Http\Resources\CropSaleResource;
use App\Models\CropHarvest;
use App\Models\CropSale;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CropSaleController extends Controller
{
    public function index(CropHarvest $cropHarvest): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [CropSale::class, $cropHarvest]);

        return CropSaleResource::collection(
            $cropHarvest->sales()->with('recorder')->latest('sale_date')->get()
        );
    }

    public function store(StoreCropSaleRequest $request, CropHarvest $cropHarvest): CropSaleResource
    {
        $this->authorize('create', [CropSale::class, $cropHarvest]);

        $sale = $cropHarvest->sales()->create([
            ...$request->validated(),
            'recorded_by' => $request->user()->id,
        ]);

        return new CropSaleResource($sale->load('recorder'));
    }

    public function show(CropSale $cropSale): CropSaleResource
    {
        $this->authorize('view', $cropSale);

        return new CropSaleResource($cropSale->load('recorder'));
    }

    public function update(UpdateCropSaleRequest $request, CropSale $cropSale): CropSaleResource
    {
        $this->authorize('update', $cropSale);

        $cropSale->update($request->validated());

        return new CropSaleResource($cropSale->load('recorder'));
    }

    public function destroy(CropSale $cropSale): Response
    {
        $this->authorize('delete', $cropSale);

        $cropSale->delete();

        return response()->noContent();
    }
}
