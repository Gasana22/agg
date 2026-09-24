<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Application\StockDesk;
use App\Modules\Inventory\Domain\Models\InventoryRequest;
use App\Modules\Inventory\Http\Resources\RequestResource;
use App\Modules\Workforce\Domain\Enums\SubjectType;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class RequestController
{
    private const WITH = ['lines.item', 'location', 'requester', 'decider'];

    public function __construct(private readonly StockDesk $desk, private readonly InventoryAccess $access) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['filter.status' => ['sometimes', 'string', 'max:100']]);
        $statuses = isset($data['filter']['status']) ? explode(',', $data['filter']['status']) : null;

        return RequestResource::collection($this->access->requests()->with(self::WITH)
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->orderByDesc('created_at')->limit(200)->get());
    }

    public function show(string $farm, InventoryRequest $inventoryRequest): RequestResource
    {
        return new RequestResource($this->visible($inventoryRequest)->load(self::WITH));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'task_id' => ['sometimes', 'nullable', 'uuid'],
            'subject_type' => ['sometimes', Rule::in(SubjectType::values())],
            'subject_id' => ['sometimes', 'nullable', 'uuid'],
            'location_id' => ['sometimes', 'nullable', 'uuid'],
            'needed_on' => ['sometimes', 'nullable', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.item_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
        ]);

        return (new RequestResource($this->desk->request($data)->load(self::WITH)))->response()->setStatusCode(201);
    }

    public function approve(Request $request, string $farm, InventoryRequest $inventoryRequest): RequestResource
    {
        $note = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:500']])['note'] ?? null;

        return new RequestResource($this->desk->decideRequest($inventoryRequest, true, $note)->load(self::WITH));
    }

    public function reject(Request $request, string $farm, InventoryRequest $inventoryRequest): RequestResource
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']]);

        return new RequestResource($this->desk->decideRequest($inventoryRequest, false, $data['note'])->load(self::WITH));
    }

    public function issue(Request $request, string $farm, InventoryRequest $inventoryRequest): RequestResource
    {
        $data = $request->validate([
            'location_id' => ['required', 'uuid'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.lot_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        return new RequestResource($this->desk->issueRequest($inventoryRequest, $data['location_id'], $data['lines'], $data['note'] ?? null)->load(self::WITH));
    }

    public function cancel(string $farm, InventoryRequest $inventoryRequest): RequestResource
    {
        return new RequestResource($this->desk->cancelRequest($this->visible($inventoryRequest))->load(self::WITH));
    }

    private function visible(InventoryRequest $r): InventoryRequest
    {
        if (! $this->access->requests()->whereKey($r->id)->exists()) {
            throw ApiException::notFound();
        }

        return $r;
    }
}
