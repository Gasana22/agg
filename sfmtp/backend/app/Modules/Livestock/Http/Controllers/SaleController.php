<?php

namespace App\Modules\Livestock\Http\Controllers;

use App\Modules\Livestock\Application\AnimalSales;
use App\Modules\Livestock\Domain\Enums\SaleStatus;
use App\Modules\Livestock\Domain\Models\SaleRequest;
use App\Modules\Livestock\Http\Resources\SaleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class SaleController
{
    public function __construct(private readonly AnimalSales $sales) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.status' => ['sometimes', Rule::in(SaleStatus::values())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        return SaleResource::collection(SaleRequest::with(['animal', 'requester'])
            ->when($data['filter']['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('created_at')->orderBy('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 50)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'animal_id' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
            'buyer' => ['nullable', 'string', 'max:150'],
            'expected_price' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
        ]);

        return (new SaleResource($this->sales->request($data)->load(['animal', 'requester'])))->response()->setStatusCode(201);
    }

    public function approve(Request $request, string $farm, SaleRequest $sale): SaleResource
    {
        return new SaleResource($this->sales->decide($sale, true, $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null)->load(['animal', 'requester']));
    }

    public function reject(Request $request, string $farm, SaleRequest $sale): SaleResource
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);

        return new SaleResource($this->sales->decide($sale, false, $data['note'])->load(['animal', 'requester']));
    }

    public function complete(Request $request, string $farm, SaleRequest $sale): SaleResource
    {
        $data = $request->validate([
            'sold_on' => ['required', 'date', 'before_or_equal:+1 day'],
            'sale_price' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
            'buyer' => ['nullable', 'string', 'max:150'],
            'withdrawal_override_reason' => ['nullable', 'string', 'max:500'],
        ]);

        return new SaleResource($this->sales->complete($sale, $data)->load(['animal', 'requester']));
    }
}
