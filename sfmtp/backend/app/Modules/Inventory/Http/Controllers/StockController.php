<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\FarmStructure\Domain\Models\Location;
use App\Modules\Inventory\Application\InventoryAlerts;
use App\Modules\Inventory\Application\StockDesk;
use App\Modules\Inventory\Domain\Models\StockBalance;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Inventory\Domain\Models\StockTransfer;
use App\Modules\Inventory\Http\Resources\BalanceResource;
use App\Modules\Inventory\Http\Resources\MovementResource;
use App\Modules\Inventory\Http\Resources\Refs;
use App\Modules\Workforce\Domain\Enums\SubjectType;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class StockController
{
    public function __construct(private readonly StockDesk $desk, private readonly InventoryAlerts $alerts) {}

    public function balances(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.location_id' => ['sometimes', 'uuid'],
            'filter.item_id' => ['sometimes', 'uuid'],
            'filter.include_empty' => ['sometimes', 'boolean'],
        ]);
        $f = $data['filter'] ?? [];

        return BalanceResource::collection(StockBalance::with(['item', 'lot', 'location'])
            ->when($f['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($f['item_id'] ?? null, fn ($q, $v) => $q->where('item_id', $v))
            ->when(! (isset($f['include_empty']) && filter_var($f['include_empty'], FILTER_VALIDATE_BOOLEAN)), fn ($q) => $q->where('quantity', '!=', 0))
            ->get()->sortBy(fn ($b) => [$b->item->name, $b->location->code, $b->lot?->expires_on?->toDateString() ?? '9999'])->values());
    }

    public function movements(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.item_id' => ['sometimes', 'uuid'],
            'filter.location_id' => ['sometimes', 'uuid'],
            'filter.type' => ['sometimes', 'string', 'max:20'],
            'filter.source_id' => ['sometimes', 'uuid'],
            'filter.from' => ['sometimes', 'date'],
            'filter.to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $f = $data['filter'] ?? [];

        return MovementResource::collection(StockMovement::with(['item', 'lot', 'location', 'recorder'])
            ->when($f['item_id'] ?? null, fn ($q, $v) => $q->where('item_id', $v))
            ->when($f['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['source_id'] ?? null, fn ($q, $v) => $q->where('source_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('occurred_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('occurred_at', '<', CarbonImmutable::parse($v)->addDay()))
            ->orderByDesc('occurred_at')->orderByDesc('created_at')->orderBy('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 50)));
    }

    public function stockIn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'uuid'],
            'location_id' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'unit_cost' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'lot_number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'expires_on' => ['sometimes', 'nullable', 'date'],
            'occurred_at' => ['sometimes', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $movement = $this->desk->stockIn($data)->load(['item', 'lot', 'location', 'recorder']);

        return (new MovementResource($movement))->response()->setStatusCode(201);
    }

    public function issue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'uuid'],
            'location_id' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'lot_id' => ['sometimes', 'nullable', 'uuid'],
            'subject_type' => ['sometimes', Rule::in(SubjectType::values())],
            'subject_id' => ['sometimes', 'nullable', 'uuid'],
            'occurred_at' => ['sometimes', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $movements = collect($this->desk->issue($data))->each->load(['item', 'lot', 'location', 'recorder']);

        return MovementResource::collection($movements)->response()->setStatusCode(201);
    }

    public function transfers(Request $request): JsonResponse
    {
        $transfers = StockTransfer::orderByDesc('occurred_at')->limit(100)->get();
        $moves = StockMovement::with(['item', 'lot'])->where('source_type', 'stock_transfer')->where('type', 'transfer_out')->whereIn('source_id', $transfers->pluck('id'))->get()->groupBy('source_id');
        $locations = Location::whereIn('id', $transfers->pluck('from_location_id')->merge($transfers->pluck('to_location_id')))->get()->keyBy('id');

        return response()->json(['data' => $transfers->map(fn ($t) => [
            'id' => $t->id,
            'code' => $t->code,
            'from' => Refs::location($locations->get($t->from_location_id)),
            'to' => Refs::location($locations->get($t->to_location_id)),
            'note' => $t->note,
            'occurred_at' => $t->occurred_at->toIso8601ZuluString(),
            'lines' => ($moves->get($t->id) ?? collect())->map(fn ($m) => ['item' => Refs::item($m->item), 'lot' => Refs::lot($m->lot), 'quantity' => abs((float) $m->quantity)])->values(),
        ])->values()]);
    }

    public function transfer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_location_id' => ['required', 'uuid'],
            'to_location_id' => ['required', 'uuid'],
            'occurred_at' => ['sometimes', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.item_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.lot_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $transfer = $this->desk->transfer($data);

        return response()->json(['data' => ['id' => $transfer->id, 'code' => $transfer->code]], 201);
    }

    public function alerts(): JsonResponse
    {
        return response()->json(['data' => $this->alerts->all()]);
    }
}
