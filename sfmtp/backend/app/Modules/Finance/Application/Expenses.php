<?php

namespace App\Modules\Finance\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Finance\Contracts\Payable;
use App\Modules\Finance\Domain\Models\Expense;
use App\Modules\Finance\Domain\Models\LedgerEntry;
use App\Modules\Media\Domain\Models\Media;
use App\Support\Database\Sequence;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Expenses (docs/04 §3). Anyone with finance.expenses.request asks; the
 * accountant's own entries within the farm's expense threshold count as
 * approved when recorded. Otherwise someone else approves: finance.manage
 * within the threshold, finance.approve (the owner) above it.
 *
 * Approval posts Dr the expense account (with its cost centre) and Cr the
 * money account it was paid from, or Cr payables until a payment settles it.
 */
class Expenses implements Payable
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FinanceAccess $access,
        private readonly Accounts $accounts,
        private readonly CostCenters $centers,
        private readonly Ledger $ledger,
    ) {}

    public function create(array $data): Expense
    {
        $account = $this->accounts->ofType($data['account_id'] ?? null, ['expense'], 'account_id');
        $paidFrom = isset($data['paid_from_account_id']) ? $this->accounts->money($data['paid_from_account_id'], 'paid_from_account_id') : null;
        [$ccType, $ccId, $ccLabel] = $this->centers->resolve($data['cost_center_type'] ?? null, $data['cost_center_id'] ?? null);
        if (! empty($data['media_id']) && ! Media::whereKey($data['media_id'])->exists()) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['media_id' => ['Upload the receipt first.']]);
        }

        return DB::transaction(function () use ($data, $account, $paidFrom, $ccType, $ccId, $ccLabel) {
            $expense = Expense::create([
                'code' => Sequence::code('expense', 'EXP'),
                'status' => 'requested',
                'account_id' => $account->id,
                'amount' => Money::fromCents(Money::cents($data['amount'])),
                'spent_on' => $data['spent_on'],
                'payee' => $data['payee'] ?? null,
                'description' => $data['description'],
                'cost_center_type' => $ccType, 'cost_center_id' => $ccId, 'cost_center_label' => $ccLabel,
                'paid_from_account_id' => $paidFrom?->id,
                'media_id' => $data['media_id'] ?? null,
                'requested_by' => Auth::id(),
            ]);
            $this->audit->record('finance.expense.requested', $expense, null, $expense->only(['code', 'amount', 'description']));

            // The accountant's own record within the threshold, or the owner's, needs no second pair of eyes.
            $threshold = $this->access->threshold('expense');
            if ($this->access->isOwner() || ($this->access->can('finance.manage') && ($threshold === null || (float) $expense->amount <= $threshold))) {
                $this->post($expense, null);
            }

            return $expense->refresh();
        });
    }

    public function approve(Expense $expense, ?string $note): Expense
    {
        $this->assertDecidable($expense);

        return DB::transaction(fn () => $this->post($expense, $note)->refresh());
    }

    public function reject(Expense $expense, string $note): Expense
    {
        $this->assertDecidable($expense);
        $expense->forceFill(['status' => 'rejected', 'decided_by' => Auth::id(), 'decided_at' => now(), 'decision_note' => $note])->save();
        $this->audit->record('finance.expense.rejected', $expense, ['status' => 'requested'], ['status' => 'rejected', 'note' => $note]);

        return $expense;
    }

    /** The requester withdraws, or finance drops, a request not yet decided. */
    public function cancel(Expense $expense): Expense
    {
        if ($expense->status !== 'requested') {
            throw ApiException::conflict('invalid_state_transition', "{$expense->code} is {$expense->status}.");
        }
        if ($expense->requested_by !== Auth::id() && ! $this->access->can('finance.manage')) {
            throw ApiException::forbidden('forbidden', 'Only the requester or finance can cancel this request.');
        }
        $expense->forceFill(['status' => 'cancelled'])->save();
        $this->audit->record('finance.expense.cancelled', $expense, ['status' => 'requested'], ['status' => 'cancelled']);

        return $expense;
    }

    /** Undo a posted expense by reversing its entry; payments against it must be voided first. */
    public function void(Expense $expense, string $reason): Expense
    {
        if (! in_array($expense->status, ['approved', 'paid'], true)) {
            throw ApiException::conflict('invalid_state_transition', "{$expense->code} is {$expense->status}.");
        }
        if (! $expense->paid_from_account_id && (float) $expense->paid_amount > 0) {
            throw ApiException::conflict('has_payments', "Void the payments of {$expense->code} first.");
        }

        return DB::transaction(function () use ($expense, $reason) {
            $entry = LedgerEntry::findOrFail($expense->ledger_entry_id);
            $this->ledger->reverseDocument($entry, "{$expense->code} voided: {$reason}");
            $old = $expense->status;
            $expense->forceFill(['status' => 'void', 'decision_note' => $reason])->save();
            $this->audit->record('finance.expense.voided', $expense, ['status' => $old], ['status' => 'void', 'reason' => $reason]);

            return $expense;
        });
    }

    private function assertDecidable(Expense $expense): void
    {
        if ($expense->status !== 'requested') {
            throw ApiException::conflict('invalid_state_transition', "{$expense->code} is {$expense->status}.");
        }
        $this->access->assertNotOwn($expense->requested_by, 'decide on this expense');
        $threshold = $this->access->threshold('expense');
        $above = $threshold !== null && (float) $expense->amount > $threshold;
        if (! $this->access->can('finance.approve') && ($above || ! $this->access->can('finance.manage'))) {
            throw new ApiException(403, 'approval_required', $above
                ? 'Expenses above '.number_format($threshold).' need the owner.'
                : 'You cannot approve expenses.', $above ? ['threshold' => $threshold] : []);
        }
    }

    private function post(Expense $expense, ?string $note): Expense
    {
        $account = $expense->account;
        $credit = $expense->paid_from_account_id ? $expense->paidFrom->code : ChartOfAccounts::PAYABLES;
        $entry = $this->ledger->post('expense', $expense->id, "{$expense->code} {$expense->description}".($expense->payee ? " ({$expense->payee})" : ''), [
            JournalLine::debit($account->code, (string) $expense->amount, $expense->cost_center_type, $expense->cost_center_id),
            JournalLine::credit($credit, (string) $expense->amount),
        ], CarbonImmutable::parse($expense->spent_on));
        $paid = (bool) $expense->paid_from_account_id;
        $expense->forceFill([
            'status' => $paid ? 'paid' : 'approved', 'paid_amount' => $paid ? $expense->amount : 0,
            'decided_by' => Auth::id(), 'decided_at' => now(), 'decision_note' => $note, 'ledger_entry_id' => $entry?->id,
        ])->save();
        $this->audit->record('finance.expense.approved', $expense, ['status' => 'requested'], ['status' => $expense->status, 'amount' => $expense->amount]);

        return $expense;
    }

    // Payable

    public function direction(): string
    {
        return 'out';
    }

    public function settlementAccount(): string
    {
        return ChartOfAccounts::PAYABLES;
    }

    public function permission(): string
    {
        return 'finance.manage';
    }

    public function lock(string $id): ?Model
    {
        return Expense::whereKey($id)->lockForUpdate()->first();
    }

    public function outstanding(Model $document): int
    {
        /** @var Expense $document */
        return $document->status === 'approved' && ! $document->paid_from_account_id ? Money::cents($document->amount) - Money::cents($document->paid_amount) : 0;
    }

    public function code(Model $document): string
    {
        return $document->code;
    }

    public function party(Model $document): ?string
    {
        return $document->payee;
    }

    public function apply(Model $document, int $cents): void
    {
        /** @var Expense $document */
        $paid = Money::cents($document->paid_amount) + $cents;
        $document->forceFill(['paid_amount' => Money::fromCents($paid), 'status' => $paid >= Money::cents($document->amount) ? 'paid' : 'approved'])->save();
    }
}
