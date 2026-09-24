<?php

namespace App\Modules\Finance\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Finance\Domain\Models\LedgerEntry;
use App\Modules\Finance\Domain\Models\Payment;
use App\Support\Database\Sequence;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Payments settle one document at a time (docs/03 §8). Money in: Dr the
 * money account, Cr receivables. Money out: Dr payables (or wages payable),
 * Cr the money account. The document is locked, so two payments can never
 * pay more than is owed; a payment is voided by reversing its entry.
 */
class PaymentDesk
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FinanceAccess $access,
        private readonly Accounts $accounts,
        private readonly Payables $payables,
        private readonly Ledger $ledger,
    ) {}

    public function record(array $data): Payment
    {
        $type = $this->payables->get($data['payable_type']);
        $this->access->assert($type->permission());
        $account = $this->accounts->money($data['account_id'] ?? null, 'account_id');
        $cents = Money::cents($data['amount']);
        $paidOn = CarbonImmutable::parse($data['paid_on'] ?? now()->toDateString());

        return DB::transaction(function () use ($data, $type, $account, $cents, $paidOn) {
            $document = $type->lock($data['payable_id']) ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['payable_id' => ['The document does not exist in this farm.']]);
            $outstanding = $type->outstanding($document);
            if ($outstanding <= 0) {
                throw ApiException::conflict('nothing_to_pay', $type->code($document).' has nothing to pay.');
            }
            if ($cents > $outstanding) {
                throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['amount' => ['At most '.Money::fromCents($outstanding).' is outstanding on '.$type->code($document).'.']]);
            }
            $payment = Payment::create([
                'code' => Sequence::code('payment', 'PMT', 4),
                'direction' => $type->direction(),
                'payable_type' => $data['payable_type'], 'payable_id' => $document->getKey(), 'payable_code' => $type->code($document),
                'party' => $type->party($document),
                'amount' => Money::fromCents($cents), 'paid_on' => $paidOn->toDateString(),
                'method' => $data['method'], 'account_id' => $account->id,
                'reference' => $data['reference'] ?? null, 'note' => $data['note'] ?? null,
                'recorded_by' => Auth::id(),
            ]);
            $in = $type->direction() === 'in';
            $memo = ($in ? 'Received from ' : 'Paid to ').($payment->party ?? 'unnamed')." for {$payment->payable_code} ({$payment->code})";
            $entry = $this->ledger->post('payment', $payment->id, $memo, $in
                ? [JournalLine::debit($account->code, (string) $payment->amount), JournalLine::credit($type->settlementAccount(), (string) $payment->amount)]
                : [JournalLine::debit($type->settlementAccount(), (string) $payment->amount), JournalLine::credit($account->code, (string) $payment->amount)], $paidOn);
            $payment->forceFill(['ledger_entry_id' => $entry?->id])->save();
            $type->apply($document, $cents);
            $this->audit->record('finance.payment.recorded', $payment, null, $payment->only(['code', 'direction', 'payable_code', 'amount', 'method']));

            return $payment->refresh();
        });
    }

    public function void(Payment $payment, string $reason): Payment
    {
        if ($payment->status !== 'posted') {
            throw ApiException::conflict('invalid_state_transition', "{$payment->code} is already void.");
        }
        $type = $this->payables->get($payment->payable_type);
        $this->access->assert($type->permission());

        return DB::transaction(function () use ($payment, $type, $reason) {
            $document = $type->lock($payment->payable_id);
            $this->ledger->reverseDocument(LedgerEntry::findOrFail($payment->ledger_entry_id), "{$payment->code} voided: {$reason}");
            $payment->forceFill(['status' => 'void', 'voided_by' => Auth::id(), 'voided_at' => now(), 'void_reason' => $reason])->save();
            if ($document) {
                $type->apply($document, -Money::cents($payment->amount));
            }
            $this->audit->record('finance.payment.voided', $payment, ['status' => 'posted'], ['status' => 'void', 'reason' => $reason]);

            return $payment;
        });
    }
}
