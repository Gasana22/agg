<?php

namespace App\Http\Controllers\Api\Procurement;

use App\Enums\NotificationType;
use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\StoreDeliveryRequest;
use App\Http\Requests\Procurement\UpdateDeliveryRequest;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Models\Notification;
use App\Models\PurchaseOrder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class DeliveryController extends Controller
{
    public function index(PurchaseOrder $purchaseOrder): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [Delivery::class, $purchaseOrder->farm]);

        return DeliveryResource::collection(
            $purchaseOrder->deliveries()->with('receiver')->latest('delivery_date')->get()
        );
    }

    /**
     * Delivery tracking here is at the purchase-order level (a date plus a
     * completeness flag), not per line item — per-item quantity
     * reconciliation belongs to the inventory module. Logging a delivery
     * moves the order to partially_delivered or delivered accordingly.
     */
    public function store(StoreDeliveryRequest $request, PurchaseOrder $purchaseOrder): DeliveryResource
    {
        $this->authorize('create', [Delivery::class, $purchaseOrder->farm]);

        $delivery = $purchaseOrder->deliveries()->create([
            ...$request->validated(),
            'received_by' => $request->user()->id,
        ]);

        if (! in_array($purchaseOrder->status, [PurchaseOrderStatus::Delivered, PurchaseOrderStatus::Cancelled], true)) {
            $newlyDelivered = $request->boolean('is_complete');

            $purchaseOrder->update([
                'status' => $newlyDelivered
                    ? PurchaseOrderStatus::Delivered->value
                    : PurchaseOrderStatus::PartiallyDelivered->value,
            ]);

            if ($newlyDelivered) {
                Notification::send(
                    $purchaseOrder->creator,
                    $purchaseOrder->farm,
                    NotificationType::PurchaseOrderDelivered,
                    "Purchase order #{$purchaseOrder->id} delivered",
                    'The full order has now been received.',
                    $purchaseOrder,
                );
            }
        }

        return new DeliveryResource($delivery->load('receiver'));
    }

    public function show(Delivery $delivery): DeliveryResource
    {
        $this->authorize('view', $delivery);

        return new DeliveryResource($delivery->load('receiver'));
    }

    public function update(UpdateDeliveryRequest $request, Delivery $delivery): DeliveryResource
    {
        $this->authorize('update', $delivery);

        $delivery->update($request->validated());

        return new DeliveryResource($delivery->load('receiver'));
    }

    public function destroy(Delivery $delivery): Response
    {
        $this->authorize('delete', $delivery);

        $delivery->delete();

        return response()->noContent();
    }
}
