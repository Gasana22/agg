<?php

namespace App\Modules\Finance\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Finance\Contracts\Payable;
use App\Modules\Finance\Domain\Models\LedgerEntry;
use App\Modules\Finance\Domain\Models\PayrollLine;
use App\Modules\Finance\Domain\Models\PayrollRun;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Workforce\Domain\Enums\SubjectType;
use App\Modules\Workforce\Domain\Enums\TaskStatus;
use App\Modules\Workforce\Domain\Models\Attendance;
use App\Modules\Workforce\Domain\Models\Task;
use App\Modules\Workforce\Domain\Models\Worker;
use App\Support\Database\Sequence;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Payroll from attendance and tasks (docs/10 Phase 8). A run covers a
 * period: each worker with a daily rate is paid for the days they checked
 * in, plus any bonus, less deductions. Wages are charged to cost centres in
 * proportion to the time of the tasks verified in the period, so labour
 * shows in the cost of each crop cycle and animal group.
 *
 * Approval (finance.payroll.approve, never the preparer unless the owner)
 * posts Dr Wages (by cost centre), Cr Wages payable (net) and Cr Payroll
 * deductions payable; payments then clear wages payable.
 */
class Payroll implements Payable
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FinanceAccess $access,
        private readonly Ledger $ledger,
        private readonly TenantContext $context,
    ) {}

    public function prepare(array $data): PayrollRun
    {
        [$start, $end] = [CarbonImmutable::parse($data['period_start']), CarbonImmutable::parse($data['period_end'])];
        if ($end->lessThan($start) || $start->diffInDays($end) > 62) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['period_end' => ['A payroll period is at most two months and ends after it starts.']]);
        }
        if ($end->isAfter(CarbonImmutable::now($this->context->farm()->timezone)->endOfDay())) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['period_end' => ['Pay only for days that have passed.']]);
        }

        return DB::transaction(function () use ($data, $start, $end) {
            $overlap = PayrollRun::where('status', '!=', 'cancelled')->whereDate('period_start', '<=', $end)->whereDate('period_end', '>=', $start)->lockForUpdate()->first();
            if ($overlap) {
                throw ApiException::conflict('overlapping_payroll', "{$overlap->code} already covers part of this period.");
            }
            $run = PayrollRun::create([
                'code' => Sequence::code('payroll', 'PRL'),
                'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
                'notes' => $data['notes'] ?? null, 'prepared_by' => Auth::id(),
            ]);
            $this->compute($run, []);
            $this->audit->record('finance.payroll.prepared', $run, null, $run->only(['code', 'total_net']) + ['period' => "{$run->period_start->toDateString()} – {$run->period_end->toDateString()}"]);

            return $run->refresh();
        });
    }

    /** Recompute a draft from attendance and tasks, keeping bonuses, deductions and notes. */
    public function recalculate(PayrollRun $run): PayrollRun
    {
        $this->assertDraft($run);

        return DB::transaction(function () use ($run) {
            $kept = $run->lines()->get()->keyBy('worker_id')->map(fn (PayrollLine $l) => ['bonus' => (string) $l->bonus, 'deductions' => (string) $l->deductions, 'note' => $l->note])->all();
            $run->lines()->delete();
            $this->compute($run, $kept);

            return $run->refresh();
        });
    }

    public function updateLine(PayrollRun $run, PayrollLine $line, array $data): PayrollRun
    {
        $this->assertDraft($run);
        if ($line->run_id !== $run->id) {
            throw ApiException::notFound();
        }

        return DB::transaction(function () use ($run, $line, $data) {
            $old = $line->only(['bonus', 'deductions', 'note']);
            $line->fill(array_intersect_key($data, array_flip(['bonus', 'deductions', 'note'])));
            $this->price($line);
            $line->save();
            $this->totals($run);
            $this->audit->record('finance.payroll.line_changed', $run, $old, $line->only(['worker_id', 'bonus', 'deductions', 'note']));

            return $run->refresh();
        });
    }

    public function approve(PayrollRun $run): PayrollRun
    {
        $this->assertDraft($run);
        $this->access->assertNotOwn($run->prepared_by, 'approve this payroll');
        if (Money::cents($run->total_gross) <= 0) {
            throw ApiException::conflict('empty_payroll', "{$run->code} has nothing to pay.");
        }

        return DB::transaction(function () use ($run) {
            $lines = $run->lines()->get();
            // Wages by cost centre, from each line's allocation.
            $byCenter = [];
            foreach ($lines as $line) {
                foreach ($line->allocation ?? [] as $a) {
                    $key = ($a['type'] ?? '').'|'.($a['id'] ?? '');
                    $byCenter[$key] = ($byCenter[$key] ?? 0) + (int) $a['amount'];
                }
            }
            $journal = [];
            foreach ($byCenter as $key => $cents) {
                [$type, $id] = explode('|', $key);
                $journal[] = JournalLine::debit(ChartOfAccounts::WAGES, Money::fromCents($cents), $type ?: null, $id ?: null);
            }
            $journal[] = JournalLine::credit(ChartOfAccounts::WAGES_PAYABLE, (string) $run->total_net);
            $journal[] = JournalLine::credit(ChartOfAccounts::DEDUCTIONS_PAYABLE, (string) $run->total_deductions);
            $entry = $this->ledger->post('payroll_run', $run->id, "Payroll {$run->code} for {$run->period_start->toDateString()} – {$run->period_end->toDateString()}", $journal, CarbonImmutable::parse($run->period_end));
            $run->forceFill(['status' => Money::cents($run->total_net) > 0 ? 'approved' : 'paid', 'approved_by' => Auth::id(), 'approved_at' => now(), 'ledger_entry_id' => $entry?->id])->save();
            $this->audit->record('finance.payroll.approved', $run, ['status' => 'draft'], ['status' => $run->status, 'total_net' => $run->total_net]);

            return $run->refresh();
        });
    }

    /** A draft is dropped; an approved run with no payments is reversed. */
    public function cancel(PayrollRun $run, string $reason): PayrollRun
    {
        if (! in_array($run->status, ['draft', 'approved'], true)) {
            throw ApiException::conflict('invalid_state_transition', "{$run->code} is {$run->status}.");
        }
        if (Money::cents($run->paid_amount) > 0) {
            throw ApiException::conflict('has_payments', "Void the payments of {$run->code} first.");
        }

        return DB::transaction(function () use ($run, $reason) {
            if ($run->ledger_entry_id) {
                $this->ledger->reverseDocument(LedgerEntry::findOrFail($run->ledger_entry_id), "{$run->code} cancelled: {$reason}");
            }
            $old = $run->status;
            $run->forceFill(['status' => 'cancelled', 'notes' => trim(($run->notes ? "{$run->notes}\n" : '')."Cancelled: {$reason}")])->save();
            $this->audit->record('finance.payroll.cancelled', $run, ['status' => $old], ['status' => 'cancelled', 'reason' => $reason]);

            return $run;
        });
    }

    /** @param  array<string, array{bonus:string, deductions:string, note:?string}>  $kept */
    private function compute(PayrollRun $run, array $kept): void
    {
        $tz = $this->context->farm()->timezone;
        [$from, $to] = [$run->period_start->toDateString(), $run->period_end->toDateString()];
        $fromUtc = CarbonImmutable::parse($from, $tz)->startOfDay()->utc();
        $toUtc = CarbonImmutable::parse($to, $tz)->endOfDay()->utc();

        $attendance = Attendance::whereBetween('work_date', [$from, $to])->get()->groupBy('worker_id');
        $tasks = Task::with('activity')->where('status', TaskStatus::Verified->value)->whereBetween('verified_at', [$fromUtc, $toUtc])->get()->groupBy('worker_id');
        $workers = Worker::whereNotNull('daily_rate')->whereIn('id', $attendance->keys())->orderBy('worker_code')->get();

        foreach ($workers as $worker) {
            $days = $attendance[$worker->id];
            $minutes = $days->sum(fn (Attendance $a) => $a->check_out_at ? (int) round($a->check_in_at->diffInMinutes($a->check_out_at)) : 0);
            $mine = $tasks[$worker->id] ?? collect();
            $line = new PayrollLine([
                'run_id' => $run->id, 'worker_id' => $worker->id,
                'days_worked' => $days->count(), 'minutes_worked' => $minutes, 'tasks_verified' => $mine->count(),
                'daily_rate' => (string) $worker->daily_rate,
                'bonus' => $kept[$worker->id]['bonus'] ?? '0', 'deductions' => $kept[$worker->id]['deductions'] ?? '0', 'note' => $kept[$worker->id]['note'] ?? null,
            ]);
            $line->setRelation('tasks', $mine);
            $this->price($line);
            $line->unsetRelation('tasks');
            $line->save();
        }
        $this->totals($run);
    }

    /** Gross = days × rate + bonus; net = gross − deductions; the gross is split over the task cost centres. */
    private function price(PayrollLine $line): void
    {
        $gross = Money::cents($line->daily_rate) * $line->days_worked + Money::cents($line->bonus);
        $deductions = Money::cents($line->deductions);
        if ($deductions > $gross) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['deductions' => ['Deductions cannot be more than the gross pay.']]);
        }
        $line->gross = Money::fromCents($gross);
        $line->net = Money::fromCents($gross - $deductions);

        $tasks = $line->relationLoaded('tasks') ? $line->getRelation('tasks') : null;
        $weights = [];
        $labels = [];
        if ($tasks !== null) {
            foreach ($tasks as $t) {
                $a = $t->activity;
                $general = ! $a || $a->subject_type === SubjectType::General;
                $key = $general ? '|' : "{$a->subject_type->value}|{$a->subject_id}";
                $weights[$key] = ($weights[$key] ?? 0) + max(1, (int) $t->worked_minutes);
                $labels[$key] = $general ? null : $a->subject_label;
            }
        } else {
            // Keep the existing split when only the bonus or deductions change.
            foreach ($line->allocation ?? [] as $a) {
                $key = ($a['type'] ?? '').'|'.($a['id'] ?? '');
                $weights[$key] = max(1, (int) $a['amount']);
                $labels[$key] = $a['label'] ?? null;
            }
        }
        if ($weights === []) {
            $weights = ['|' => 1];
            $labels = ['|' => null];
        }
        $total = array_sum($weights);
        $allocation = [];
        $left = $gross;
        $keys = array_keys($weights);
        foreach ($keys as $i => $key) {
            $cents = $i === count($keys) - 1 ? $left : intdiv($gross * $weights[$key], $total);
            $left -= $cents;
            [$type, $id] = explode('|', $key);
            $allocation[] = ['type' => $type ?: null, 'id' => $id ?: null, 'label' => $labels[$key], 'amount' => $cents];
        }
        $line->allocation = $allocation;
    }

    private function totals(PayrollRun $run): void
    {
        $lines = $run->lines()->get();
        $run->forceFill([
            'total_gross' => Money::fromCents($lines->sum(fn ($l) => Money::cents($l->gross))),
            'total_deductions' => Money::fromCents($lines->sum(fn ($l) => Money::cents($l->deductions))),
            'total_net' => Money::fromCents($lines->sum(fn ($l) => Money::cents($l->net))),
        ])->save();
    }

    private function assertDraft(PayrollRun $run): void
    {
        if ($run->status !== 'draft') {
            throw ApiException::conflict('invalid_state_transition', "{$run->code} is {$run->status}.");
        }
    }

    // Payable

    public function direction(): string
    {
        return 'out';
    }

    public function settlementAccount(): string
    {
        return ChartOfAccounts::WAGES_PAYABLE;
    }

    public function permission(): string
    {
        return 'finance.manage|finance.payroll.manage';
    }

    public function lock(string $id): ?Model
    {
        return PayrollRun::whereKey($id)->lockForUpdate()->first();
    }

    public function outstanding(Model $document): int
    {
        /** @var PayrollRun $document */
        return $document->status === 'approved' ? Money::cents($document->total_net) - Money::cents($document->paid_amount) : 0;
    }

    public function code(Model $document): string
    {
        return $document->code;
    }

    public function party(Model $document): ?string
    {
        return 'Workers ('.$document->lines()->count().')';
    }

    public function apply(Model $document, int $cents): void
    {
        /** @var PayrollRun $document */
        $paid = Money::cents($document->paid_amount) + $cents;
        $document->forceFill(['paid_amount' => Money::fromCents($paid), 'status' => $paid >= Money::cents($document->total_net) ? 'paid' : 'approved'])->save();
    }
}
