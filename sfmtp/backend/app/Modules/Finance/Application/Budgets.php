<?php

namespace App\Modules\Finance\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Finance\Domain\Models\Budget;
use App\Modules\Tenancy\TenantContext;
use App\Support\Database\Sequence;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Budgets: planned amounts per income or expense account for a period, for
 * the whole farm or one cost centre (a crop cycle, animal group …). The
 * actual figures come straight from the ledger, so they need no upkeep.
 */
class Budgets
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Accounts $accounts,
        private readonly CostCenters $centers,
        private readonly TenantContext $context,
    ) {}

    public function create(array $data): Budget
    {
        [$type, $id, $label] = $this->centers->resolve($data['scope_type'] ?? null, $data['scope_id'] ?? null, 'scope_id');

        return DB::transaction(function () use ($data, $type, $id, $label) {
            $budget = Budget::create([
                'code' => Sequence::code('budget', 'BUD'),
                'name' => $data['name'], 'period_start' => $data['period_start'], 'period_end' => $data['period_end'],
                'scope_type' => $type, 'scope_id' => $id, 'scope_label' => $label,
                'notes' => $data['notes'] ?? null, 'created_by' => Auth::id(),
            ]);
            $this->writeLines($budget, $data['lines']);
            $this->audit->record('finance.budget.created', $budget, null, $budget->only(['code', 'name']) + ['lines' => count($data['lines'])]);

            return $budget->refresh();
        });
    }

    public function update(Budget $budget, array $data): Budget
    {
        return DB::transaction(function () use ($budget, $data) {
            $old = $budget->only(['name', 'period_start', 'period_end', 'status', 'notes']);
            $budget->fill(array_intersect_key($data, array_flip(['name', 'period_start', 'period_end', 'status', 'notes'])));
            if ($budget->period_end->lessThan($budget->period_start)) {
                throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['period_end' => ['The period ends before it starts.']]);
            }
            $budget->save();
            if (isset($data['lines'])) {
                $budget->lines()->delete();
                $this->writeLines($budget, $data['lines']);
            }
            $this->audit->record('finance.budget.updated', $budget, $old, array_diff_key($data, ['lines' => 1]) + (isset($data['lines']) ? ['lines' => count($data['lines'])] : []));

            return $budget;
        });
    }

    /**
     * Budget against actual per line: expenses as debits less credits,
     * income as credits less debits, within the period (and cost centre).
     *
     * @return array<int, array{account_id:string, code:string, name:string, type:string, budget:float, actual:float, variance:float, used_pct:?float}>
     */
    public function versusActual(Budget $budget): array
    {
        $budget->loadMissing('lines.account');
        $actuals = DB::table('ledger_lines as l')->join('ledger_entries as e', 'e.id', '=', 'l.entry_id')
            ->where('l.farm_id', $this->context->farmId())
            ->whereIn('l.account_id', $budget->lines->pluck('account_id'))
            ->whereBetween('e.posted_on', [$budget->period_start->toDateString(), $budget->period_end->toDateString()])
            ->when($budget->scope_type, fn ($q) => $q->where('l.cost_center_type', $budget->scope_type)->where('l.cost_center_id', $budget->scope_id))
            ->groupBy('l.account_id')->select('l.account_id', DB::raw('SUM(l.debit) AS d'), DB::raw('SUM(l.credit) AS c'))
            ->get()->keyBy('account_id');

        return $budget->lines->sortBy(fn ($l) => $l->account->code)->map(function ($line) use ($actuals) {
            $a = $actuals->get($line->account_id);
            $d = Money::cents($a->d ?? 0);
            $c = Money::cents($a->c ?? 0);
            $actual = $line->account->type === 'income' ? $c - $d : $d - $c;
            $planned = Money::cents($line->amount);

            return [
                'line_id' => $line->id,
                'account_id' => $line->account_id, 'code' => $line->account->code, 'name' => $line->account->name, 'type' => $line->account->type,
                'budget' => (float) Money::fromCents($planned), 'actual' => (float) Money::fromCents($actual),
                'variance' => (float) Money::fromCents($planned - $actual),
                'used_pct' => $planned > 0 ? round($actual * 100 / $planned, 1) : null,
                'note' => $line->note,
            ];
        })->values()->all();
    }

    private function writeLines(Budget $budget, array $lines): void
    {
        $seen = [];
        foreach ($lines as $i => $line) {
            $account = $this->accounts->ofType($line['account_id'] ?? null, ['income', 'expense'], "lines.{$i}.account_id");
            if (isset($seen[$account->id])) {
                throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ["lines.{$i}.account_id" => ["{$account->code} is already in this budget."]]);
            }
            $seen[$account->id] = true;
            $budget->lines()->create(['account_id' => $account->id, 'amount' => Money::of($line['amount']), 'note' => $line['note'] ?? null]);
        }
    }
}
