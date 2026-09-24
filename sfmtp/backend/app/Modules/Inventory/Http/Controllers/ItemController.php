<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Application\Items;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\Models\StockBalance;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Inventory\Http\Resources\BalanceResource;
use App\Modules\Inventory\Http\Resources\ItemResource;
use App\Modules\Inventory\Http\Resources\MovementResource;
use App\Support\Http\OptimisticLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ItemController
{
    public function __construct(private readonly Items $items) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.category_id' => ['sometimes', 'uuid'],
            'filter.search' => ['sometimes', 'string', 'max:100'],
            'filter.low_stock' => ['sometimes', 'boolean'],
            'filter.active' => ['sometimes', 'boolean'],
        ]);
        $f = $data['filter'] ?? [];
        $items = InventoryItem::with(['category', 'defaultLocation'])
            ->withSum('balances as on_hand', 'quantity')
            ->withSum('balances as stock_value', 'value')
            ->where('is_active', ! isset($f['active']) || filter_var($f['active'], FILTER_VALIDATE_BOOLEAN))
            ->when($f['category_id'] ?? null, fn ($q, $v) => $q->where('category_id', $v))
            ->when($f['search'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('name', 'like', "%{$v}%")->orWhere('code', 'like', "%{$v}%")->orWhere('sku', 'like', "%{$v}%")))
            ->orderBy('name')->get();
        if (isset($f['low_stock']) && filter_var($f['low_stock'], FILTER_VALIDATE_BOOLEAN)) {
            $items = $items->filter(fn ($i) => $i->reorder_level !== null && (float) ($i->on_hand ?? 0) <= (float) $i->reorder_level)->values();
        }

        return ItemResource::collection($items);
    }

    public function show(string $farm, InventoryItem $item): JsonResponse
    {
        $item->load(['category', 'defaultLocation'])->loadSum('balances as on_hand', 'quantity')->loadSum('balances as stock_value', 'value');
        $balances = StockBalance::with(['item', 'lot', 'location'])->where('item_id', $item->id)->where('quantity', '!=', 0)->get()
            ->sortBy(fn ($b) => [$b->location->code, $b->lot?->expires_on?->toDateString() ?? '9999', $b->lot?->code])->values();
        $movements = StockMovement::with(['item', 'lot', 'location', 'recorder'])->where('item_id', $item->id)->orderByDesc('occurred_at')->orderByDesc('created_at')->limit(30)->get();

        return response()->json(['data' => (new ItemResource($item))->resolve($request = request()) + [
            'balances' => BalanceResource::collection($balances)->resolve($request),
            'recent_movements' => MovementResource::collection($movements)->resolve($request),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $item = $this->items->create($request->validate($this->rules(true)));

        return (new ItemResource($item->load(['category', 'defaultLocation'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $farm, InventoryItem $item): ItemResource
    {
        OptimisticLock::check($request, $item);

        return new ItemResource($this->items->update($item, $request->validate($this->rules(false)))->load(['category', 'defaultLocation']));
    }

    private function rules(bool $creating): array
    {
        $r = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$r, 'string', 'min:2', 'max:150'],
            'category_id' => [$r, 'uuid', 'exists:global_inventory_categories,id'],
            'unit' => [$r, 'string', 'max:20'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:60'],
            'reorder_level' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999'],
            'tracks_lots' => ['sometimes', 'boolean'],
            'tracks_expiry' => ['sometimes', 'boolean'],
            'default_location_id' => ['sometimes', 'nullable', 'uuid'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
