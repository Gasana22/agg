<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Models\LedgerAccount;
use App\Modules\Finance\Domain\Models\LedgerEntry;
use App\Modules\Tenancy\TenantContext;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Posts balanced journal entries (docs/09: `LedgerService::post`). Callers
 * post inside their own transaction, so the document and its entry commit
 * together. Lines with a zero amount are dropped; an entry that does not
 * balance is refused. Entries are corrected by reversal only.
 */
class Ledger
{
    /** Source of entries typed in by finance; the only ones that can be reversed by hand. */
    public const MANUAL = 'manual';

    public function __construct(private readonly ChartOfAccounts $chart) {}

    /** @param  array<int, JournalLine>  $lines */
    public function post(string $sourceType, ?string $sourceId, string $memo, array $lines, ?CarbonImmutable $postedOn = null, ?string $reverses = null): ?LedgerEntry
    {
        $lines = array_values(array_filter($lines, fn (JournalLine $l) => Money::cents($l->debit) !== 0 || Money::cents($l->credit) !== 0));
        if ($lines === []) {
            return null;
        }
        $debits = array_sum(array_map(fn (JournalLine $l) => Money::cents($l->debit), $lines));
        $credits = array_sum(array_map(fn (JournalLine $l) => Money::cents($l->credit), $lines));
        if ($debits !== $credits) {
            throw new ApiException(500, 'unbalanced_entry', 'The journal entry does not balance.', ['debits' => Money::fromCents($debits), 'credits' => Money::fromCents($credits)]);
        }

        return DB::transaction(function () use ($sourceType, $sourceId, $memo, $lines, $postedOn, $reverses) {
            $entry = LedgerEntry::create([
                'number' => $this->nextNumber(),
                'posted_on' => ($postedOn ?? CarbonImmutable::now())->toDateString(),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'memo' => mb_substr($memo, 0, 300),
                'reverses_entry_id' => $reverses,
                'posted_by' => Auth::id(),
            ]);
            foreach ($lines as $l) {
                // Negative amounts flip sides, so callers can post signed values.
                [$debit, $credit] = [Money::cents($l->debit), Money::cents($l->credit)];
                if ($debit < 0 || $credit < 0) {
                    [$debit, $credit] = [max(0, -$credit, $debit), max(0, -$debit, $credit)];
                }
                $entry->lines()->create([
                    'account_id' => $this->chart->account($l->account)->id,
                    'debit' => Money::fromCents($debit),
                    'credit' => Money::fromCents($credit),
                    'cost_center_type' => $l->costCenterType,
                    'cost_center_id' => $l->costCenterId,
                    'memo' => $l->memo,
                ]);
            }

            return $entry->load('lines.account');
        });
    }

    /**
     * Post the mirror image of a manual entry. Entries posted by a document
     * (a stock movement, a delivery, an invoice, a payment) are corrected
     * through that document, which voids it and calls reverseDocument(), so
     * the books and the sub-ledgers stay in step.
     */
    public function reverse(LedgerEntry $entry, string $reason): LedgerEntry
    {
        if ($entry->source_type !== self::MANUAL) {
            throw ApiException::conflict('posted_by_document', "{$entry->number} was posted by a ".str_replace('_', ' ', $entry->source_type).'; correct it there (void the document, or count the stock), so the books and the documents stay in step.');
        }

        return $this->mirror($entry, $reason);
    }

    /** Reverse the entry of a document being voided; for the document's own service. */
    public function reverseDocument(LedgerEntry $entry, string $reason, ?CarbonImmutable $postedOn = null): LedgerEntry
    {
        return $this->mirror($entry, $reason, $postedOn);
    }

    /**
     * A journal entry typed in by finance. Control accounts (stock,
     * receivables, payables, wages) and inactive accounts are refused.
     *
     * @param  array<int, array{account_id:string, debit?:string|float|null, credit?:string|float|null, cost_center_type?:?string, cost_center_id?:?string, memo?:?string}>  $lines
     */
    public function postManual(string $memo, array $lines, CarbonImmutable $postedOn): LedgerEntry
    {
        $journal = [];
        foreach ($lines as $i => $l) {
            $account = LedgerAccount::find($l['account_id']) ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ["lines.{$i}.account_id" => ['Unknown account.']]);
            if ($account->isControl()) {
                throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ["lines.{$i}.account_id" => ["{$account->code} {$account->name} is kept by its documents; record the document instead."]]);
            }
            if (! $account->is_active) {
                throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ["lines.{$i}.account_id" => ["{$account->code} {$account->name} is inactive."]]);
            }
            $debit = Money::cents($l['debit'] ?? 0);
            $credit = Money::cents($l['credit'] ?? 0);
            if (($debit > 0) === ($credit > 0) || $debit < 0 || $credit < 0) {
                throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ["lines.{$i}.debit" => ['Enter a debit or a credit, not both.']]);
            }
            $journal[] = new JournalLine($account->code, Money::fromCents($debit), Money::fromCents($credit), $l['cost_center_type'] ?? null, $l['cost_center_id'] ?? null, $l['memo'] ?? null);
        }
        $debits = array_sum(array_map(fn (JournalLine $l) => Money::cents($l->debit), $journal));
        $credits = array_sum(array_map(fn (JournalLine $l) => Money::cents($l->credit), $journal));
        if ($debits !== $credits) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['lines' => ['Debits ('.Money::fromCents($debits).') and credits ('.Money::fromCents($credits).') must be equal.']]);
        }

        return $this->post(self::MANUAL, null, $memo, $journal, $postedOn);
    }

    private function mirror(LedgerEntry $entry, string $reason, ?CarbonImmutable $postedOn = null): LedgerEntry
    {
        if ($entry->reverses_entry_id) {
            throw ApiException::conflict('invalid_state_transition', 'A reversal cannot itself be reversed; post a new entry.');
        }
        if (LedgerEntry::where('reverses_entry_id', $entry->id)->exists()) {
            throw ApiException::conflict('already_reversed', "{$entry->number} is already reversed.");
        }
        $lines = $entry->lines()->with('account')->get()->map(fn ($l) => new JournalLine($l->account->code, (string) $l->credit, (string) $l->debit, $l->cost_center_type, $l->cost_center_id, $l->memo))->all();

        return $this->post($entry->source_type, $entry->source_id, "Reversal of {$entry->number}: {$reason}", $lines, $postedOn, $entry->id);
    }

    /** The next number from the farm's counter; the row lock serialises concurrent postings. */
    private function nextNumber(): string
    {
        $farmId = app(TenantContext::class)->farmId();
        $row = fn () => DB::table('ledger_sequences')->where('farm_id', $farmId)->lockForUpdate()->value('last_number');
        // Lock first; insert only when missing (INSERT IGNORE on an existing key deadlocks on MySQL).
        $last = $row();
        if ($last === null) {
            DB::table('ledger_sequences')->insertOrIgnore(['farm_id' => $farmId, 'last_number' => 0]);
            $last = $row();
        }
        $last = (int) $last;
        DB::table('ledger_sequences')->where('farm_id', $farmId)->update(['last_number' => $last + 1]);

        return 'JE-'.str_pad((string) ($last + 1), 5, '0', STR_PAD_LEFT);
    }
}
