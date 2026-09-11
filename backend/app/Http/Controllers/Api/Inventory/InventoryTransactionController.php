<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInventoryTransactionRequest;
use App\Http\Requests\Inventory\UpdateInventoryTransactionRequest;
use App\Http\Resources\InventoryTransactionResource;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class InventoryTransactionController extends Controller
{
    public function index(InventoryItem $inventoryItem): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [InventoryTransaction::class, $inventoryItem->farm]);

        return InventoryTransactionResource::collection(
            $inventoryItem->transactions()->with('recorder')->latest('date')->get()
        );
    }

    public function store(StoreInventoryTransactionRequest $request, InventoryItem $inventoryItem): InventoryTransactionResource
    {
        $this->authorize('create', [InventoryTransaction::class, $inventoryItem->farm]);

        $transaction = $inventoryItem->transactions()->create([
            ...$request->validated(),
            'farm_id' => $inventoryItem->farm_id,
            'recorded_by' => $request->user()->id,
        ]);

        return new InventoryTransactionResource($transaction->load('recorder'));
    }

    public function show(InventoryTransaction $inventoryTransaction): InventoryTransactionResource
    {
        $this->authorize('view', $inventoryTransaction);

        return new InventoryTransactionResource($inventoryTransaction->load('recorder'));
    }

    public function update(UpdateInventoryTransactionRequest $request, InventoryTransaction $inventoryTransaction): InventoryTransactionResource
    {
        $this->authorize('update', $inventoryTransaction);

        $inventoryTransaction->update($request->validated());

        return new InventoryTransactionResource($inventoryTransaction->load('recorder'));
    }

    public function destroy(InventoryTransaction $inventoryTransaction): Response
    {
        $this->authorize('delete', $inventoryTransaction);

        $inventoryTransaction->delete();

        return response()->noContent();
    }
}
