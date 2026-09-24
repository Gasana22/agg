<?php

namespace App\Modules\Finance\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Finance\Domain\Models\IncomeRecord;
use App\Modules\Finance\Domain\Models\LedgerEntry;
use App\Modules\Media\Domain\Models\Media;
use App\Support\Database\Sequence;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Money received without an invoice. Recording posts Dr the money account,
 * Cr the income account (with its cost centre); voiding posts the reversal.
 */
class IncomeBook
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Accounts $accounts,
        private readonly CostCenters $centers,
        private readonly Ledger $ledger,
    ) {}

    public function record(array $data): IncomeRecord
    {
        $account = $this->accounts->ofType($data['account_id'] ?? null, ['income'], 'account_id');
        $into = $this->accounts->money($data['received_into_account_id'] ?? null, 'received_into_account_id');
        [$ccType, $ccId, $ccLabel] = $this->centers->resolve($data['cost_center_type'] ?? null, $data['cost_center_id'] ?? null);
        if (! empty($data['media_id']) && ! Media::whereKey($data['media_id'])->exists()) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['media_id' => ['Upload the file first.']]);
        }

        return DB::transaction(function () use ($data, $account, $into, $ccType, $ccId, $ccLabel) {
            $income = IncomeRecord::create([
                'code' => Sequence::code('income', 'INC'),
                'account_id' => $account->id, 'received_into_account_id' => $into->id,
                'amount' => Money::of($data['amount']), 'received_on' => $data['received_on'],
                'payer' => $data['payer'] ?? null, 'description' => $data['description'],
                'cost_center_type' => $ccType, 'cost_center_id' => $ccId, 'cost_center_label' => $ccLabel,
                'media_id' => $data['media_id'] ?? null, 'recorded_by' => Auth::id(),
            ]);
            $entry = $this->ledger->post('income', $income->id, "{$income->code} {$income->description}".($income->payer ? " ({$income->payer})" : ''), [
                JournalLine::debit($into->code, (string) $income->amount),
                JournalLine::credit($account->code, (string) $income->amount, $ccType, $ccId),
            ], CarbonImmutable::parse($income->received_on));
            $income->forceFill(['ledger_entry_id' => $entry?->id])->save();
            $this->audit->record('finance.income.recorded', $income, null, $income->only(['code', 'amount', 'description']));

            return $income->refresh();
        });
    }

    public function void(IncomeRecord $income, string $reason): IncomeRecord
    {
        if ($income->status !== 'recorded') {
            throw ApiException::conflict('invalid_state_transition', "{$income->code} is {$income->status}.");
        }

        return DB::transaction(function () use ($income, $reason) {
            $this->ledger->reverseDocument(LedgerEntry::findOrFail($income->ledger_entry_id), "{$income->code} voided: {$reason}");
            $income->forceFill(['status' => 'void', 'voided_by' => Auth::id(), 'voided_at' => now(), 'void_reason' => $reason])->save();
            $this->audit->record('finance.income.voided', $income, ['status' => 'recorded'], ['status' => 'void', 'reason' => $reason]);

            return $income;
        });
    }
}
