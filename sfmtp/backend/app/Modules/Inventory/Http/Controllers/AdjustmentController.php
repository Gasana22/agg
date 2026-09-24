<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Application\StockDesk;
use App\Modules\Inventory\Domain\Models\StockAdjustment;
use App\Modules\Inventory\Http\Resources\AdjustmentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AdjustmentController
{
    private const WITH = ['lines.item', 'lines.lot', 'location', 'proposer', 'decider'];

    public function __construct(private readonly StockDesk $desk) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['filter.status' => ['sometimes', Rule::in(['proposed', 'approved', 'rejected', 'cancelled'])]]);

        return AdjustmentResource::collection(StockAdjustment::with(self::WITH)
            ->when($data['filter']['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('created_at')->limit(200)->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => ['required', 'uuid'],
            'lines.*.lot_id' => ['sometimes', 'nullable', 'uuid'],
            'lines.*.counted_quantity' => ['required', 'numeric', 'min:0', 'max:999999999'],
        ]);

        return (new AdjustmentResource($this->desk->proposeAdjustment($data)->load(self::WITH)))->response()->setStatusCode(201);
    }

    public function approve(Request $request, string $farm, StockAdjustment $adjustment): AdjustmentResource
    {
        $note = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:500']])['note'] ?? null;

        return new AdjustmentResource($this->desk->decideAdjustment($adjustment, true, $note)->load(self::WITH));
    }

    public function reject(Request $request, string $farm, StockAdjustment $adjustment): AdjustmentResource
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']]);

        return new AdjustmentResource($this->desk->decideAdjustment($adjustment, false, $data['note'])->load(self::WITH));
    }
}
