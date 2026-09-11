<?php

namespace App\Http\Controllers\Api\Procurement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\UpdatePurchaseOrderItemRequest;
use App\Http\Resources\PurchaseOrderItemResource;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\Response;

class PurchaseOrderItemController extends Controller
{
    public function update(UpdatePurchaseOrderItemRequest $request, PurchaseOrderItem $purchaseOrderItem): PurchaseOrderItemResource
    {
        $this->authorize('update', $purchaseOrderItem);

        $purchaseOrderItem->update($request->validated());

        return new PurchaseOrderItemResource($purchaseOrderItem);
    }

    public function destroy(PurchaseOrderItem $purchaseOrderItem): Response
    {
        $this->authorize('delete', $purchaseOrderItem);

        $purchaseOrderItem->delete();

        return response()->noContent();
    }
}
