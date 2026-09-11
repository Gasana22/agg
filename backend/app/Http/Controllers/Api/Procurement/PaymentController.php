<?php

namespace App\Http\Controllers\Api\Procurement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\StorePaymentRequest;
use App\Http\Requests\Procurement\UpdatePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PaymentController extends Controller
{
    public function index(PurchaseOrder $purchaseOrder): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [Payment::class, $purchaseOrder->farm]);

        return PaymentResource::collection(
            $purchaseOrder->payments()->with('recorder')->latest('payment_date')->get()
        );
    }

    public function store(StorePaymentRequest $request, PurchaseOrder $purchaseOrder): PaymentResource
    {
        $this->authorize('create', [Payment::class, $purchaseOrder->farm]);

        $payment = $purchaseOrder->payments()->create([
            ...$request->validated(),
            'recorded_by' => $request->user()->id,
        ]);

        return new PaymentResource($payment->load('recorder'));
    }

    public function show(Payment $payment): PaymentResource
    {
        $this->authorize('view', $payment);

        return new PaymentResource($payment->load('recorder'));
    }

    public function update(UpdatePaymentRequest $request, Payment $payment): PaymentResource
    {
        $this->authorize('update', $payment);

        $payment->update($request->validated());

        return new PaymentResource($payment->load('recorder'));
    }

    public function destroy(Payment $payment): Response
    {
        $this->authorize('delete', $payment);

        $payment->delete();

        return response()->noContent();
    }
}
