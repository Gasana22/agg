<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Modules\Procurement\Application\Purchasing;
use App\Modules\Procurement\Domain\Models\PurchaseRequest;
use App\Modules\Procurement\Http\Resources\PurchaseRequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseRequestController
{
    private const WITH = ['lines.item', 'requester', 'decider'];

    public function __construct(private readonly Purchasing $purchasing) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['filter.status' => ['sometimes', 'string', 'max:100']]);
        $statuses = isset($data['filter']['status']) ? explode(',', $data['filter']['status']) : null;

        return PurchaseRequestResource::collection(PurchaseRequest::with(self::WITH)
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->orderByDesc('created_at')->limit(200)->get());
    }

    public function show(string $farm, PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        return new PurchaseRequestResource($purchaseRequest->load(self::WITH));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'needed_by' => ['sometimes', 'nullable', 'date'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.item_id' => ['sometimes', 'nullable', 'uuid'],
            'lines.*.description' => ['required_without:lines.*.item_id', 'nullable', 'string', 'max:200'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'lines.*.unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            'lines.*.estimated_unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        return (new PurchaseRequestResource($this->purchasing->request($data)->load(self::WITH)))->response()->setStatusCode(201);
    }

    public function approve(Request $request, string $farm, PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        $note = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:500']])['note'] ?? null;

        return new PurchaseRequestResource($this->purchasing->decideRequest($purchaseRequest, true, $note)->load(self::WITH));
    }

    public function reject(Request $request, string $farm, PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']]);

        return new PurchaseRequestResource($this->purchasing->decideRequest($purchaseRequest, false, $data['note'])->load(self::WITH));
    }

    public function cancel(string $farm, PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        return new PurchaseRequestResource($this->purchasing->cancelRequest($purchaseRequest)->load(self::WITH));
    }
}
