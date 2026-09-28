<?php

namespace App\Modules\Integrations\Payments;

use App\Modules\Integrations\Application\ProviderConfig;
use App\Modules\Integrations\Application\ProviderFailure;
use App\Modules\Integrations\Domain\Models\OnlinePayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Flutterwave Standard (v3): hosted checkout for mobile money (MTN, Airtel)
 * and cards. Settings: `secret_key`, `webhook_hash` (the secret hash set on
 * the Flutterwave dashboard), optional `payment_options` and `logo_url`.
 * Farms' customer payments settle into the farm's Flutterwave subaccount.
 */
class Flutterwave implements PaymentAdapter
{
    private const API = 'https://api.flutterwave.com/v3';

    public function checkout(ProviderConfig $config, OnlinePayment $payment, array $customer, string $redirectUrl, ?string $subaccount): string
    {
        $res = Http::withToken($config->require('secret_key'))->acceptJson()->timeout(15)->post(self::API.'/payments', array_filter([
            'tx_ref' => $payment->reference,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'redirect_url' => $redirectUrl,
            'payment_options' => $config->get('payment_options') ?? 'mobilemoneyuganda,card',
            'customer' => array_filter(['email' => $customer['email'], 'name' => $customer['name'], 'phonenumber' => $customer['phone']]),
            'customizations' => array_filter(['title' => 'SFMTP', 'description' => $payment->description, 'logo' => $config->get('logo_url')]),
            'meta' => ['purpose' => $payment->purpose, 'subject' => $payment->subject_code],
            'subaccounts' => $subaccount ? [['id' => $subaccount]] : null,
        ]));
        if (! $res->successful() || $res->json('status') !== 'success' || ! $res->json('data.link')) {
            throw new ProviderFailure('Flutterwave could not open a checkout: '.($res->json('message') ?? "HTTP {$res->status()}"), retryable: true, status: $res->status());
        }

        return (string) $res->json('data.link');
    }

    public function verify(ProviderConfig $config, OnlinePayment $payment): array
    {
        $res = Http::withToken($config->require('secret_key'))->acceptJson()->timeout(15)
            ->get(self::API.'/transactions/verify_by_reference', ['tx_ref' => $payment->reference]);
        if ($res->status() === 404 || ($res->status() === 400 && str_contains((string) $res->json('message'), 'No transaction'))) {
            // The customer has not paid (yet): nothing at Flutterwave for this reference.
            return ['status' => 'pending', 'amount' => null, 'currency' => null, 'tx_id' => null, 'reason' => null];
        }
        if (! $res->successful() || $res->json('status') !== 'success') {
            throw new ProviderFailure("Flutterwave verify answered HTTP {$res->status()}", retryable: true, status: $res->status());
        }
        $data = $res->json('data');
        if (($data['tx_ref'] ?? null) !== $payment->reference) {
            throw new ProviderFailure('Flutterwave returned another transaction.', retryable: false);
        }
        $status = match ($data['status'] ?? '') {
            'successful' => 'succeeded',
            'failed', 'cancelled' => 'failed',
            default => 'pending',
        };

        return ['status' => $status, 'amount' => isset($data['amount']) ? (float) $data['amount'] : null, 'currency' => $data['currency'] ?? null,
            'tx_id' => isset($data['id']) ? (string) $data['id'] : null, 'reason' => $status === 'failed' ? ($data['processor_response'] ?? 'Payment failed') : null];
    }

    public function webhookReference(ProviderConfig $config, Request $request): ?string
    {
        $hash = $config->get('webhook_hash');
        if ($hash === null || ! hash_equals($hash, (string) $request->header('verif-hash'))) {
            return null;
        }

        return $request->input('data.tx_ref') ?? $request->input('txRef');
    }
}
