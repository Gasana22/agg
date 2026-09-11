<?php

namespace App\Http\Controllers\Api\LivestockManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\LivestockManagement\StoreAnimalSaleRequest;
use App\Http\Requests\LivestockManagement\UpdateAnimalSaleRequest;
use App\Http\Resources\AnimalSaleResource;
use App\Models\Animal;
use App\Models\AnimalSale;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AnimalSaleController extends Controller
{
    public function index(Animal $animal): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [AnimalSale::class, $animal]);

        return AnimalSaleResource::collection(
            $animal->sales()->with('recorder')->latest('sale_date')->get()
        );
    }

    public function store(StoreAnimalSaleRequest $request, Animal $animal): AnimalSaleResource
    {
        $this->authorize('create', [AnimalSale::class, $animal]);

        $sale = $animal->sales()->create([
            ...$request->validated(),
            'recorded_by' => $request->user()->id,
        ]);

        return new AnimalSaleResource($sale->load('recorder'));
    }

    public function show(AnimalSale $animalSale): AnimalSaleResource
    {
        $this->authorize('view', $animalSale);

        return new AnimalSaleResource($animalSale->load('recorder'));
    }

    public function update(UpdateAnimalSaleRequest $request, AnimalSale $animalSale): AnimalSaleResource
    {
        $this->authorize('update', $animalSale);

        $animalSale->update($request->validated());

        return new AnimalSaleResource($animalSale->load('recorder'));
    }

    public function destroy(AnimalSale $animalSale): Response
    {
        $this->authorize('delete', $animalSale);

        $animalSale->delete();

        return response()->noContent();
    }
}
