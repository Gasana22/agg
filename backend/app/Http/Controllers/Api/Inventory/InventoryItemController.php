<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInventoryItemRequest;
use App\Http\Requests\Inventory\UpdateInventoryItemRequest;
use App\Http\Resources\InventoryItemResource;
use App\Models\Farm;
use App\Models\InventoryItem;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class InventoryItemController extends Controller
{
    public function index(Farm $farm): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [InventoryItem::class, $farm]);

        return InventoryItemResource::collection($farm->inventoryItems()->orderBy('name')->get());
    }

    public function store(StoreInventoryItemRequest $request, Farm $farm): InventoryItemResource
    {
        $this->authorize('create', [InventoryItem::class, $farm]);

        $item = $farm->inventoryItems()->create([
            ...$request->validated(),
            'is_active' => true,
        ]);

        return new InventoryItemResource($item);
    }

    public function show(InventoryItem $inventoryItem): InventoryItemResource
    {
        $this->authorize('view', $inventoryItem);

        return new InventoryItemResource($inventoryItem);
    }

    public function update(UpdateInventoryItemRequest $request, InventoryItem $inventoryItem): InventoryItemResource
    {
        $this->authorize('update', $inventoryItem);

        $inventoryItem->update($request->validated());

        return new InventoryItemResource($inventoryItem);
    }

    public function destroy(InventoryItem $inventoryItem): Response
    {
        $this->authorize('delete', $inventoryItem);

        $inventoryItem->delete();

        return response()->noContent();
    }
}
