<?php

namespace App\Http\Controllers\Api\Procurement;

use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\StorePurchaseOrderRequest;
use App\Http\Requests\Procurement\UpdatePurchaseOrderRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\Farm;
use App\Models\PurchaseOrder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    private const WITH = ['supplier', 'items', 'payments', 'deliveries.receiver', 'creator'];

    public function index(Farm $farm): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [PurchaseOrder::class, $farm]);

        return PurchaseOrderResource::collection(
            $farm->purchaseOrders()->with(self::WITH)->latest('order_date')->get()
        );
    }

    /**
     * Creates the order and its line items together in one transaction —
     * a purchase order is never created without at least one item.
     */
    public function store(StorePurchaseOrderRequest $request, Farm $farm): PurchaseOrderResource
    {
        $this->authorize('create', [PurchaseOrder::class, $farm]);

        $purchaseOrder = DB::transaction(function () use ($request, $farm) {
            $purchaseOrder = $farm->purchaseOrders()->create([
                'supplier_id' => $request->validated('supplier_id'),
                'order_date' => $request->validated('order_date'),
                'expected_delivery_date' => $request->validated('expected_delivery_date'),
                'status' => PurchaseOrderStatus::Ordered->value,
                'notes' => $request->validated('notes'),
                'created_by' => $request->user()->id,
            ]);

            foreach ($request->validated('items') as $item) {
                $purchaseOrder->items()->create($item);
            }

            return $purchaseOrder;
        });

        return new PurchaseOrderResource($purchaseOrder->load(self::WITH));
    }

    public function show(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $this->authorize('view', $purchaseOrder);

        return new PurchaseOrderResource($purchaseOrder->load(self::WITH));
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $this->authorize('update', $purchaseOrder);

        $purchaseOrder->update($request->validated());

        return new PurchaseOrderResource($purchaseOrder->load(self::WITH));
    }

    public function destroy(PurchaseOrder $purchaseOrder): Response
    {
        $this->authorize('delete', $purchaseOrder);

        $purchaseOrder->delete();

        return response()->noContent();
    }
}
