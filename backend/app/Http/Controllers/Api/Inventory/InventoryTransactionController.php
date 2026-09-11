<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Enums\FarmRole;
use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInventoryTransactionRequest;
use App\Http\Requests\Inventory\UpdateInventoryTransactionRequest;
use App\Http\Resources\InventoryTransactionResource;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Notification;
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

    /**
     * Only notifies when this transaction is the one that pushes the item
     * newly at-or-under its reorder level — not on every stock_out once
     * it's already low, which would spam a notification per transaction.
     */
    public function store(StoreInventoryTransactionRequest $request, InventoryItem $inventoryItem): InventoryTransactionResource
    {
        $this->authorize('create', [InventoryTransaction::class, $inventoryItem->farm]);

        $wasLowStock = $inventoryItem->isLowStock();

        $transaction = $inventoryItem->transactions()->create([
            ...$request->validated(),
            'farm_id' => $inventoryItem->farm_id,
            'recorded_by' => $request->user()->id,
        ]);

        if (! $wasLowStock && $inventoryItem->fresh()->isLowStock()) {
            Notification::sendToFarmRoles(
                $inventoryItem->farm,
                [FarmRole::FarmOwner, FarmRole::FarmManager, FarmRole::StoreManager],
                NotificationType::InventoryLowStock,
                "{$inventoryItem->name} is low on stock",
                "Current quantity is at or below the reorder level ({$inventoryItem->reorder_level} {$inventoryItem->unit}).",
                $inventoryItem,
            );
        }

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
