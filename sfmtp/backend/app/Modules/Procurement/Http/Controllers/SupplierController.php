<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Modules\Procurement\Application\Purchasing;
use App\Modules\Procurement\Domain\Models\Supplier;
use App\Modules\Procurement\Http\Resources\SupplierResource;
use App\Support\Http\OptimisticLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SupplierController
{
    public function __construct(private readonly Purchasing $purchasing) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $all = $request->boolean('include_inactive');

        return SupplierResource::collection(Supplier::when(! $all, fn ($q) => $q->where('is_active', true))->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        return (new SupplierResource($this->purchasing->createSupplier($request->validate($this->rules(true)))))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $farm, Supplier $supplier): SupplierResource
    {
        OptimisticLock::check($request, $supplier);

        return new SupplierResource($this->purchasing->updateSupplier($supplier, $request->validate($this->rules(false))));
    }

    private function rules(bool $creating): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:150'],
            'contact_person' => ['sometimes', 'nullable', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'address' => ['sometimes', 'nullable', 'string', 'max:300'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'payment_terms_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
