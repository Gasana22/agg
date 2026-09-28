<?php

namespace App\Modules\Integrations\Http\Controllers;

use App\Modules\Integrations\Domain\Models\OnlinePayment;
use App\Modules\Integrations\Payments\OnlinePayments;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class OnlinePaymentController
{
    public function __construct(private readonly OnlinePayments $payments) {}

    /** The payer's own payment; a pending one is checked with the gateway (at most every 5 s). */
    public function show(Request $request, string $reference): JsonResponse
    {
        $payment = OnlinePayment::where('reference', $reference)->where('created_by', $request->user()->id)->first() ?? throw ApiException::notFound();
        if ($payment->status === 'pending' && Cache::add("payments:checked:{$payment->id}", true, 5)) {
            $payment = $this->payments->refresh($payment);
        }

        return new JsonResponse(['data' => $payment->toApi()]);
    }

    /** Gateway webhooks: signed with the provider's secret; the payment is re-checked with the gateway. */
    public function webhook(Request $request, string $provider): JsonResponse
    {
        if (! $this->payments->webhook($provider, $request)) {
            throw ApiException::unauthenticated('invalid_signature', 'The webhook signature is not valid.');
        }

        return new JsonResponse(['data' => ['received' => true]]);
    }
}
