<?php

namespace App\Modules\Integrations\Payments;

use App\Modules\Integrations\Application\ProviderConfig;
use App\Modules\Integrations\Domain\Models\OnlinePayment;
use Illuminate\Http\Request;

interface PaymentAdapter
{
    /**
     * Open a hosted checkout page for the payment.
     *
     * @param  array{email:?string, name:?string, phone:?string}  $customer
     * @return string the checkout URL
     */
    public function checkout(ProviderConfig $config, OnlinePayment $payment, array $customer, string $redirectUrl, ?string $subaccount): string;

    /**
     * Ask the gateway what happened to a payment. Never trust a redirect or a
     * webhook body alone.
     *
     * @return array{status:string, amount:?float, currency:?string, tx_id:?string, reason:?string} status succeeded | failed | pending
     */
    public function verify(ProviderConfig $config, OnlinePayment $payment): array;

    /** The payment reference a webhook call is about, or null when its signature is wrong. */
    public function webhookReference(ProviderConfig $config, Request $request): ?string;
}
