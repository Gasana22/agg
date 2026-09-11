<?php

namespace App\Http\Controllers\Api\Procurement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\StoreSupplierRequest;
use App\Http\Requests\Procurement\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Farm;
use App\Models\Supplier;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SupplierController extends Controller
{
    public function index(Farm $farm): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [Supplier::class, $farm]);

        return SupplierResource::collection($farm->suppliers()->orderBy('name')->get());
    }

    public function store(StoreSupplierRequest $request, Farm $farm): SupplierResource
    {
        $this->authorize('create', [Supplier::class, $farm]);

        $supplier = $farm->suppliers()->create([
            ...$request->validated(),
            'is_active' => true,
        ]);

        return new SupplierResource($supplier);
    }

    public function show(Supplier $supplier): SupplierResource
    {
        $this->authorize('view', $supplier);

        return new SupplierResource($supplier);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): SupplierResource
    {
        $this->authorize('update', $supplier);

        $supplier->update($request->validated());

        return new SupplierResource($supplier);
    }

    public function destroy(Supplier $supplier): Response
    {
        $this->authorize('delete', $supplier);

        $supplier->delete();

        return response()->noContent();
    }
}
