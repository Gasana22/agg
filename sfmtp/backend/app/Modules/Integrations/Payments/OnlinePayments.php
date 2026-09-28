<?php

namespace App\Modules\Integrations\Payments;

use App\Modules\Integrations\Application\ProviderConfig;
use App\Modules\Integrations\Application\ProviderFailure;
use App\Modules\Integrations\Contracts\PaymentPurpose;
use App\Modules\Integrations\Contracts\ProviderDirectory;
use App\Modules\Integrations\Domain\Models\OnlinePayment;
use App\Support\Http\ApiException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Online payments (ADR-0018). A module starts one for something it owns (a
 * subscription, an invoice); the payer is sent to the gateway's hosted
 * checkout; the result is confirmed with the gateway, never taken from the
 * redirect or the webhook body, and handed to the module's PaymentPurpose
 * to record in its books, once.
 *
 * Payments do not fail over: a payment stays with the gateway where it
 * started. The default payment provider is used.
 */
class OnlinePayments
{
    public const ADAPTERS = ['flutterwave' => Flutterwave::class];

    public function __construct(private readonly ProviderDirectory $directory) {}

    public function available(): bool
    {
        return $this->provider() !== null;
    }

    /**
     * @param  array{purpose:string, subject_id:string, subject_code:?string, farm_id:?string, amount:string|float, currency:string, description:string, return_path:string}  $data
     * @param  array{email:?string, name:?string, phone:?string}  $customer
     */
    public function start(array $data, array $customer, ?string $subaccount = null): OnlinePayment
    {
        $config = $this->provider() ?? throw ApiException::conflict('online_payment_unavailable', 'Online payment is not available. Pay by another method.');

        // Coming back to "Pay" within half an hour reuses the open checkout.
        $open = OnlinePayment::where('purpose', $data['purpose'])->where('subject_id', $data['subject_id'])->where('created_by', Auth::id())
            ->where('status', 'pending')->where('amount', $data['amount'])->where('created_at', '>=', now()->subMinutes(30))->whereNotNull('checkout_url')->latest()->first();
        if ($open) {
            return $open;
        }

        $payment = OnlinePayment::create([
            'reference' => 'SFMTP-'.strtoupper(Str::random(14)),
            'provider_id' => $config->id,
            'provider' => $config->provider,
            'status' => 'pending',
            'created_by' => Auth::id(),
        ] + $data);

        try {
            $url = $this->adapter($config)->checkout($config, $payment, $customer,
                rtrim(config('sfmtp.web_url'), '/').'/payments/return?reference='.$payment->reference, $subaccount);
        } catch (ProviderFailure $e) {
            $this->directory->report($config->id, false, $e->getMessage());
            $payment->forceFill(['status' => 'failed', 'failure_reason' => 'The payment page could not be opened.'])->save();
            throw new ApiException(502, 'payment_gateway_error', 'The payment service did not answer. Try again in a few minutes.');
        }
        $this->directory->report($config->id, true);
        $payment->forceFill(['checkout_url' => $url])->save();

        return $payment;
    }

    /** Ask the gateway about a pending payment and record it once confirmed. */
    public function refresh(OnlinePayment $payment): OnlinePayment
    {
        if ($payment->status !== 'pending') {
            return $payment;
        }
        $config = $this->directory->find($payment->provider_id) ?? throw ApiException::conflict('online_payment_unavailable', 'The payment provider is no longer configured.');
        try {
            $result = $this->adapter($config)->verify($config, $payment);
        } catch (ProviderFailure $e) {
            Log::warning('Payment verification failed', ['reference' => $payment->reference, 'error' => $e->getMessage()]);

            return $payment;   // still pending; the webhook or the next look tries again
        }

        return $this->apply($payment, $result);
    }

    /**
     * A gateway webhook: find the payment it is about and confirm it with the
     * gateway. The body is only a hint.
     */
    public function webhook(string $provider, Request $request): bool
    {
        foreach ($this->directory->candidates('payment') as $config) {
            if ($config->provider !== $provider || ! isset(self::ADAPTERS[$provider])) {
                continue;
            }
            $reference = $this->adapter($config)->webhookReference($config, $request);
            if ($reference === null) {
                return false;
            }
            $payment = OnlinePayment::where('reference', $reference)->first();
            if ($payment) {
                $this->refresh($payment);
            }

            return true;
        }

        return false;
    }

    private function apply(OnlinePayment $payment, array $result): OnlinePayment
    {
        if ($result['status'] === 'pending') {
            return $payment;
        }

        return DB::transaction(function () use ($payment, $result) {
            $payment = OnlinePayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status !== 'pending') {
                return $payment;   // another request got there first
            }
            if ($result['status'] === 'failed') {
                $payment->forceFill(['status' => 'failed', 'failure_reason' => mb_substr((string) $result['reason'], 0, 300), 'verified_at' => now(),
                    'provider_tx_id' => $result['tx_id']])->save();

                return $payment;
            }
            if ($result['currency'] !== $payment->currency || (float) $result['amount'] + 0.005 < (float) $payment->amount) {
                // Money moved, but not the amount asked: left for the platform team to reconcile by hand.
                $payment->forceFill(['status' => 'failed', 'paid_amount' => $result['amount'], 'provider_tx_id' => $result['tx_id'], 'verified_at' => now(),
                    'failure_reason' => "Paid {$result['amount']} {$result['currency']} instead of {$payment->amount} {$payment->currency}; contact support."])->save();
                Log::error('Online payment amount mismatch', ['reference' => $payment->reference]);

                return $payment;
            }
            $payment->forceFill(['status' => 'succeeded', 'paid_amount' => $result['amount'], 'provider_tx_id' => $result['tx_id'], 'verified_at' => now()])->save();
            try {
                $this->purpose($payment->purpose)->fulfil($payment);
                $payment->forceFill(['fulfilled_at' => now()])->save();
            } catch (Throwable $e) {
                // Paid at the gateway: keep that, and flag the booking for follow-up.
                Log::error('Online payment could not be recorded', ['reference' => $payment->reference, 'exception' => $e]);
                $payment->forceFill(['failure_reason' => 'Paid, but not yet recorded: '.mb_substr($e->getMessage(), 0, 250)])->save();
            }

            return $payment;
        });
    }

    private function provider(): ?ProviderConfig
    {
        foreach ($this->directory->candidates('payment') as $config) {
            if (isset(self::ADAPTERS[$config->provider])) {
                return $config;
            }
        }

        return null;
    }

    private function adapter(ProviderConfig $config): PaymentAdapter
    {
        return app(self::ADAPTERS[$config->provider] ?? throw new ProviderFailure("No payment adapter for {$config->provider}."));
    }

    private function purpose(string $key): PaymentPurpose
    {
        foreach (app()->tagged('sfmtp.payment-purposes') as $purpose) {
            if ($purpose->key() === $key) {
                return $purpose;
            }
        }

        throw new \RuntimeException("No payment purpose {$key}.");
    }
}
