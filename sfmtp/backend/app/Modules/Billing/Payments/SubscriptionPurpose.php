<?php

namespace App\Modules\Billing\Payments;

use App\Modules\Billing\Application\SubscriptionService;
use App\Modules\Billing\Domain\Models\Subscription;
use App\Modules\Integrations\Contracts\PaymentPurpose;
use App\Modules\Integrations\Domain\Models\OnlinePayment;
use App\Support\Http\ApiException;

/** An owner paying the organization's subscription online (ADR-0018). */
class SubscriptionPurpose implements PaymentPurpose
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function key(): string
    {
        return 'subscription';
    }

    public function fulfil(OnlinePayment $payment): void
    {
        $subscription = Subscription::with('plan')->findOrFail($payment->subject_id);
        try {
            $this->subscriptions->recordPayment($subscription, [
                'amount' => $payment->paid_amount ?? $payment->amount,
                'currency' => $payment->currency,
                'provider' => $payment->provider,
                'provider_ref' => $payment->provider_tx_id ?? $payment->reference,
                'notes' => "Online payment {$payment->reference}",
            ], null);
        } catch (ApiException $e) {
            if ($e->errorCode !== 'duplicate') {
                throw $e;
            }
        }
    }
}
