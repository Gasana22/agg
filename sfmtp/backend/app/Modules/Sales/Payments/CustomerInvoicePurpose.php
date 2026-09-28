<?php

namespace App\Modules\Sales\Payments;

use App\Modules\Finance\Application\ChartOfAccounts;
use App\Modules\Finance\Application\Money;
use App\Modules\Finance\Application\PaymentDesk;
use App\Modules\Finance\Domain\Models\LedgerAccount;
use App\Modules\Integrations\Contracts\PaymentPurpose;
use App\Modules\Integrations\Domain\Models\OnlinePayment;
use App\Modules\Sales\Domain\Models\CustomerInvoice;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\TenantContext;

/**
 * A customer paying a farm's invoice online (ADR-0018). The money settles in
 * the farm's Flutterwave subaccount and is booked as a mobile money receipt
 * against the invoice, in the farm's context.
 */
class CustomerInvoicePurpose implements PaymentPurpose
{
    public function __construct(private readonly TenantContext $context, private readonly PaymentDesk $desk) {}

    public function key(): string
    {
        return 'customer_invoice';
    }

    public function fulfil(OnlinePayment $payment): void
    {
        $this->context->run(Farm::findOrFail($payment->farm_id), function () use ($payment) {
            $invoice = CustomerInvoice::findOrFail($payment->subject_id);
            $outstanding = Money::cents($invoice->amount) - Money::cents($invoice->paid_amount);
            if ($outstanding <= 0) {
                return;   // settled another way meanwhile; the platform team refunds the difference
            }
            $this->desk->recordConfirmed([
                'payable_type' => 'customer_invoice',
                'payable_id' => $invoice->id,
                'amount' => Money::fromCents(min($outstanding, Money::cents($payment->paid_amount ?? $payment->amount))),
                'paid_on' => now($this->context->farm()->timezone)->toDateString(),
                'method' => 'mobile_money',
                'account_id' => LedgerAccount::where('code', ChartOfAccounts::MOBILE_MONEY)->value('id'),
                'reference' => "{$payment->provider}:".($payment->provider_tx_id ?? $payment->reference),
                'note' => "Paid online ({$payment->reference})",
            ]);
        });
    }
}
