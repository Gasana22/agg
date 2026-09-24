<?php

namespace App\Modules\Reporting\Application;

use App\Modules\Finance\Application\Budgets;
use App\Modules\Finance\Application\ChartOfAccounts;
use App\Modules\Finance\Application\Money;
use App\Modules\Finance\Domain\Models\Budget;
use App\Modules\Finance\Domain\Models\Expense;
use App\Modules\Finance\Domain\Models\LedgerEntry;
use App\Modules\Finance\Domain\Models\PayrollRun;
use App\Modules\Inventory\Domain\Models\StockAdjustment;
use App\Modules\Livestock\Domain\Enums\SaleStatus;
use App\Modules\Livestock\Domain\Models\SaleRequest;
use App\Modules\Procurement\Domain\Models\PurchaseOrder;
use App\Modules\Sales\Domain\Models\CustomerInvoice;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Finance figures for the accountant's and owner's dashboards (docs/05 §3.2, §3.7). */
class FinanceMetrics
{
    public function __construct(private readonly TenantContext $context, private readonly FinanceReports $reports) {}

    /** @return array{income: float, expenses: float, net: float} */
    public function profit(Period $p): array
    {
        return $this->context->remember("reporting.profit.{$p->from->toDateString()}.{$p->to->toDateString()}",
            fn () => $this->reports->profitAndLoss($p->from->toDateString(), $p->to->toDateString())['totals']);
    }

    public function cashBalance(): string
    {
        $r = DB::table('ledger_lines as l')->join('ledger_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.farm_id', $this->context->farmId())->where('a.is_cash', true)
            ->selectRaw('COALESCE(SUM(l.debit), 0) AS d, COALESCE(SUM(l.credit), 0) AS c')->first();

        return Money::fromCents(Money::cents($r->d) - Money::cents($r->c));
    }

    public function receivables(): string
    {
        return $this->balance(ChartOfAccounts::RECEIVABLES, debit: true);
    }

    public function payables(): string
    {
        return $this->balance(ChartOfAccounts::PAYABLES, debit: false);
    }

    public function wagesToPay(): string
    {
        return $this->balance(ChartOfAccounts::WAGES_PAYABLE, debit: false);
    }

    /** @return array{budget: int, actual: int} cents of expense budgets running today */
    public function budgetToday(): array
    {
        return $this->context->remember('reporting.budget_today', function () {
            $today = CarbonImmutable::now($this->context->farm()->timezone)->toDateString();
            $totals = ['budget' => 0, 'actual' => 0];
            $budgets = Budget::with('lines.account')->where('status', 'active')->whereDate('period_start', '<=', $today)->whereDate('period_end', '>=', $today)->get();
            foreach ($budgets as $b) {
                foreach (app(Budgets::class)->versusActual($b) as $l) {
                    if ($l['type'] === 'expense') {
                        $totals['budget'] += Money::cents($l['budget']);
                        $totals['actual'] += Money::cents($l['actual']);
                    }
                }
            }

            return $totals;
        });
    }

    public function approvalsPending(): int
    {
        return Expense::where('status', 'requested')->count()
            + PayrollRun::where('status', 'draft')->count()
            + PurchaseOrder::where('status', 'draft')->count()
            + StockAdjustment::where('status', 'proposed')->count()
            + SaleRequest::where('status', SaleStatus::Requested->value)->count();
    }

    // Widgets

    public function expensesToApprove(): array
    {
        $currency = $this->context->farm()->currency;

        return Expense::with(['requester', 'account'])->where('status', 'requested')->orderBy('created_at')->limit(10)->get()
            ->map(fn (Expense $e) => [
                'id' => $e->id,
                'title' => "{$e->code} · {$e->description}",
                'subtitle' => number_format((float) $e->amount)." {$currency} · {$e->account->name} · ".($e->requester?->name ?? ''),
                'at' => $e->created_at?->toIso8601ZuluString(),
                'badge' => ['label' => 'To approve', 'tone' => 'warning'],
                'href' => "/farms/{$e->farm_id}/finance?tab=expenses&status=requested",
            ])->values()->all();
    }

    public function customerInvoicesOverdue(): array
    {
        $currency = $this->context->farm()->currency;
        $today = CarbonImmutable::now($this->context->farm()->timezone)->toDateString();

        return CustomerInvoice::with('customer')->where('status', 'issued')->orderBy('due_on')->limit(10)->get()
            ->map(fn (CustomerInvoice $i) => [
                'id' => $i->id,
                'title' => "{$i->code} · {$i->customer->name}",
                'subtitle' => number_format((float) $i->amount - (float) $i->paid_amount)." {$currency} outstanding",
                'at' => $i->due_on ? $i->due_on->toDateString().'T12:00:00Z' : null,
                'badge' => $i->due_on && $i->due_on->toDateString() < $today ? ['label' => 'Overdue', 'tone' => 'danger'] : ['label' => 'Due', 'tone' => 'neutral'],
                'href' => "/farms/{$i->farm_id}/finance/invoices/{$i->id}",
            ])->values()->all();
    }

    public function payrollPending(): array
    {
        $currency = $this->context->farm()->currency;

        return PayrollRun::withCount('lines')->whereIn('status', ['draft', 'approved'])->orderBy('period_start')->limit(10)->get()
            ->map(fn (PayrollRun $r) => [
                'id' => $r->id,
                'title' => "{$r->code} · {$r->period_start->toDateString()} – {$r->period_end->toDateString()}",
                'subtitle' => "{$r->lines_count} workers · ".number_format((float) $r->total_net - (float) $r->paid_amount)." {$currency} to pay",
                'at' => $r->created_at?->toIso8601ZuluString(),
                'badge' => $r->status === 'draft' ? ['label' => 'To approve', 'tone' => 'warning'] : ['label' => 'To pay', 'tone' => 'info'],
                'href' => "/farms/{$r->farm_id}/finance/payroll/{$r->id}",
            ])->values()->all();
    }

    public function recentTransactions(): array
    {
        return LedgerEntry::with('lines')->orderByDesc('created_at')->limit(10)->get()
            ->map(fn (LedgerEntry $e) => [
                'id' => $e->id,
                'title' => "{$e->number} · {$e->memo}",
                'subtitle' => number_format((float) $e->lines->sum('debit')).' '.$this->context->farm()->currency.' · '.str_replace('_', ' ', $e->source_type),
                'at' => $e->created_at?->toIso8601ZuluString(),
                'href' => "/farms/{$e->farm_id}/ledger?tab=journal",
            ])->values()->all();
    }

    /** @return array{labels: list<string>, budget: list<float>, actual: list<float>} expense budgets running today */
    public function budgetVsActual(): array
    {
        $today = CarbonImmutable::now($this->context->farm()->timezone)->toDateString();
        $out = ['labels' => [], 'budget' => [], 'actual' => []];
        foreach (Budget::with('lines.account')->where('status', 'active')->whereDate('period_start', '<=', $today)->whereDate('period_end', '>=', $today)->orderBy('code')->limit(8)->get() as $b) {
            $lines = array_filter(app(Budgets::class)->versusActual($b), fn ($l) => $l['type'] === 'expense');
            $out['labels'][] = $b->scope_label ? "{$b->name} ({$b->scope_label})" : $b->name;
            $out['budget'][] = round(array_sum(array_column($lines, 'budget')), 2);
            $out['actual'][] = round(array_sum(array_column($lines, 'actual')), 2);
        }

        return $out;
    }

    private function balance(string $code, bool $debit): string
    {
        $r = DB::table('ledger_lines as l')->join('ledger_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.farm_id', $this->context->farmId())->where('a.code', $code)
            ->selectRaw('COALESCE(SUM(l.debit), 0) AS d, COALESCE(SUM(l.credit), 0) AS c')->first();

        return Money::fromCents($debit ? Money::cents($r->d) - Money::cents($r->c) : Money::cents($r->c) - Money::cents($r->d));
    }
}
