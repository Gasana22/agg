<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Sales\Application\Invoicing;
use App\Modules\Sales\Domain\Models\Customer;
use App\Modules\Sales\Http\Resources\CustomerResource;
use App\Support\Http\OptimisticLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController
{
    public function __construct(private readonly Invoicing $invoicing) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return CustomerResource::collection(Customer::when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        return (new CustomerResource($this->invoicing->createCustomer($request->validate($this->rules(true)))))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $farm, Customer $customer): CustomerResource
    {
        OptimisticLock::check($request, $customer);

        return new CustomerResource($this->invoicing->updateCustomer($customer, $request->validate($this->rules(false))));
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
