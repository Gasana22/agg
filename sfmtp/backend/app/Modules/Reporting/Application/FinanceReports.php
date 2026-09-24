<?php

namespace App\Modules\Reporting\Application;

use App\Modules\Catalog\Application\Units;
use App\Modules\Crops\Domain\Models\CropCycle;
use App\Modules\Finance\Application\ChartOfAccounts;
use App\Modules\Finance\Application\Money;
use App\Modules\Livestock\Domain\Models\Animal;
use App\Modules\Livestock\Domain\Models\AnimalGroup;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Financial statements read straight from the ledger (docs/03 §8): profit
 * and loss, cash flow with a 90-day forecast from open documents, and cost
 * and margin per crop cycle and animal group from the lines' cost centres.
 * Amounts are computed in cents and returned as numbers with two decimals.
 */
class FinanceReports
{
    public const ACRES_PER_HA = 2.4710538;

    public function __construct(private readonly TenantContext $context, private readonly Units $units) {}

    /**
     * @return array{period: array, income: list<array>, expenses: list<array>, totals: array{income: float, expenses: float, net: float}}
     */
    public function profitAndLoss(string $from, string $to, ?string $centerType = null, ?string $centerId = null): array
    {
        $rows = $this->lines($from, $to)
            ->whereIn('a.type', ['income', 'expense'])
            ->when($centerType, fn ($q) => $q->where('l.cost_center_type', $centerType)->where('l.cost_center_id', $centerId))
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')
            ->select('a.id', 'a.code', 'a.name', 'a.type', DB::raw('SUM(l.debit) AS d'), DB::raw('SUM(l.credit) AS c'))
            ->orderBy('a.code')->get();
        $shape = fn ($r) => ['account_id' => $r->id, 'code' => $r->code, 'name' => $r->name,
            'amount' => $this->money($r->type === 'income' ? Money::cents($r->c) - Money::cents($r->d) : Money::cents($r->d) - Money::cents($r->c))];
        $income = $rows->where('type', 'income')->map($shape)->values()->all();
        $expenses = $rows->where('type', 'expense')->map($shape)->values()->all();
        $ti = array_sum(array_map(fn ($r) => Money::cents($r['amount']), $income));
        $te = array_sum(array_map(fn ($r) => Money::cents($r['amount']), $expenses));

        return [
            'period' => ['from' => $from, 'to' => $to],
            'cost_center' => $centerType ? ['type' => $centerType, 'id' => $centerId] : null,
            'income' => $income,
            'expenses' => $expenses,
            'totals' => ['income' => $this->money($ti), 'expenses' => $this->money($te), 'net' => $this->money($ti - $te)],
        ];
    }

    /** @return array{labels: list<string>, income: list<float>, expenses: list<float>, net: list<float>} per calendar month, oldest first */
    public function monthly(int $months = 12): array
    {
        $tz = $this->context->farm()->timezone;
        $first = CarbonImmutable::now($tz)->startOfMonth()->subMonths($months - 1);
        $rows = $this->lines($first->toDateString(), CarbonImmutable::now($tz)->endOfMonth()->toDateString())
            ->whereIn('a.type', ['income', 'expense'])
            ->select('e.posted_on', 'a.type', 'l.debit', 'l.credit')->get();
        $by = [];
        foreach ($rows as $r) {
            $month = substr((string) $r->posted_on, 0, 7);
            $amount = $r->type === 'income' ? Money::cents($r->credit) - Money::cents($r->debit) : Money::cents($r->debit) - Money::cents($r->credit);
            $by[$month][$r->type] = ($by[$month][$r->type] ?? 0) + $amount;
        }
        $out = ['labels' => [], 'income' => [], 'expenses' => [], 'net' => []];
        for ($i = 0; $i < $months; $i++) {
            $m = $first->addMonths($i)->format('Y-m');
            $in = $by[$m]['income'] ?? 0;
            $ex = $by[$m]['expense'] ?? 0;
            $out['labels'][] = $m;
            $out['income'][] = $this->money($in);
            $out['expenses'][] = $this->money($ex);
            $out['net'][] = $this->money($in - $ex);
        }

        return $out;
    }

    /** Money in and out of the cash, mobile money and bank accounts, by kind of document. */
    public function cashFlow(string $from, string $to): array
    {
        $cash = DB::table('ledger_accounts')->where('farm_id', $this->context->farmId())->where('is_cash', true)->pluck('id');
        $opening = $this->cashBalance($cash, CarbonImmutable::parse($from)->subDay()->toDateString());
        $rows = $this->lines($from, $to)->whereIn('l.account_id', $cash)
            ->groupBy('e.source_type')->select('e.source_type', DB::raw('SUM(l.debit) AS d'), DB::raw('SUM(l.credit) AS c'))->get();
        $in = $rows->mapWithKeys(fn ($r) => [$r->source_type => Money::cents($r->d)])->filter();
        $out = $rows->mapWithKeys(fn ($r) => [$r->source_type => Money::cents($r->c)])->filter();
        $closing = $opening + $in->sum() - $out->sum();
        $accounts = DB::table('ledger_accounts')->where('farm_id', $this->context->farmId())->where('is_cash', true)->orderBy('code')->get()
            ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'balance' => $this->money($this->cashBalance(collect([$a->id]), $to))])->values();

        return [
            'period' => ['from' => $from, 'to' => $to],
            'opening' => $this->money($opening),
            'inflows' => $in->map(fn ($c, $k) => ['source' => $k, 'amount' => $this->money($c)])->values(),
            'outflows' => $out->map(fn ($c, $k) => ['source' => $k, 'amount' => $this->money($c)])->values(),
            'net' => $this->money($in->sum() - $out->sum()),
            'closing' => $this->money($closing),
            'accounts' => $accounts,
        ];
    }

    /**
     * The next weeks' cash from open documents: customer invoices due in,
     * supplier invoices, approved expenses and payroll due out. Overdue
     * items fall in the first week.
     */
    public function forecast(int $weeks = 13): array
    {
        $tz = $this->context->farm()->timezone;
        $today = CarbonImmutable::now($tz)->startOfDay();
        $cash = DB::table('ledger_accounts')->where('farm_id', $this->context->farmId())->where('is_cash', true)->pluck('id');
        $balance = $this->cashBalance($cash, $today->toDateString());
        $farm = $this->context->farmId();
        $open = fn (string $table, string $amount, string $paid, string $due, array $statuses) => DB::table($table)->where('farm_id', $farm)->whereIn('status', $statuses)
            ->select(DB::raw("{$due} AS due"), DB::raw("{$amount} - {$paid} AS open"))->get();

        $items = collect()
            ->concat($open('customer_invoices', 'amount', 'paid_amount', 'due_on', ['issued'])->map(fn ($r) => [$r->due, Money::cents($r->open)]))
            ->concat($open('supplier_invoices', 'amount', 'paid_amount', 'due_on', ['recorded'])->map(fn ($r) => [$r->due, -Money::cents($r->open)]))
            ->concat($open('expenses', 'amount', 'paid_amount', 'spent_on', ['approved'])->map(fn ($r) => [$r->due, -Money::cents($r->open)]))
            ->concat($open('payroll_runs', 'total_net', 'paid_amount', 'period_end', ['approved'])->map(fn ($r) => [$r->due, -Money::cents($r->open)]));

        $buckets = [];
        for ($w = 0; $w < $weeks; $w++) {
            $buckets[$w] = ['week_start' => $today->addWeeks($w)->toDateString(), 'in' => 0, 'out' => 0];
        }
        foreach ($items as [$due, $cents]) {
            $w = $due ? max(0, intdiv((int) $today->diffInDays(CarbonImmutable::parse($due), false), 7)) : 0;
            if ($w >= $weeks || $cents === 0) {
                continue;
            }
            $buckets[$w][$cents > 0 ? 'in' : 'out'] += abs($cents);
        }
        $running = $balance;
        $series = [];
        foreach ($buckets as $b) {
            $running += $b['in'] - $b['out'];
            $series[] = ['week_start' => $b['week_start'], 'in' => $this->money($b['in']), 'out' => $this->money($b['out']), 'balance' => $this->money($running)];
        }

        return ['today' => $today->toDateString(), 'balance' => $this->money($balance), 'weeks' => $series];
    }

    /**
     * Cost, revenue and margin per crop cycle, with cost per hectare, per
     * acre and per kilogram harvested. Costs include inputs issued from
     * stock, wages from payroll and expenses charged to the cycle.
     */
    public function cropCycles(?string $from = null, ?string $to = null): array
    {
        $sums = $this->byCenter('crop_cycle', $from, $to);
        $cycles = CropCycle::with(['crop', 'plot', 'harvests'])->whereIn('id', array_keys($sums))->orWhere('stage', '!=', 'closed')->orderBy('code')->get();

        return $cycles->map(function (CropCycle $c) use ($sums) {
            $s = $sums[$c->id] ?? ['inputs' => 0, 'labour' => 0, 'other' => 0, 'revenue' => 0];
            $cost = $s['inputs'] + $s['labour'] + $s['other'];
            $kg = round($c->harvests->sum(fn ($h) => $this->units->toKg((float) $h->quantity, $h->unit) ?? 0), 1);
            $ha = (float) $c->area_ha;

            return [
                'id' => $c->id, 'code' => $c->code, 'crop' => $c->crop?->label(), 'plot' => $c->plot?->code, 'stage' => $c->stage->value,
                'area_ha' => $ha, 'harvested_kg' => $kg,
                'inputs' => $this->money($s['inputs']), 'labour' => $this->money($s['labour']), 'other' => $this->money($s['other']),
                'cost' => $this->money($cost), 'revenue' => $this->money($s['revenue']), 'margin' => $this->money($s['revenue'] - $cost),
                'cost_per_ha' => $ha > 0 ? $this->money((int) round($cost / $ha)) : null,
                'cost_per_acre' => $ha > 0 ? $this->money((int) round($cost / ($ha * self::ACRES_PER_HA))) : null,
                'cost_per_kg' => $kg > 0 ? $this->money((int) round($cost / $kg)) : null,
            ];
        })->values()->all();
    }

    /** Cost, revenue and margin per animal group; animal-level lines count for the animal's group. */
    public function animalGroups(?string $from = null, ?string $to = null): array
    {
        $sums = $this->byCenter('animal_group', $from, $to);
        foreach ($this->byCenter('animal', $from, $to) as $animalId => $s) {
            $group = Animal::whereKey($animalId)->value('group_id') ?? 'ungrouped';
            foreach ($s as $k => $v) {
                $sums[$group][$k] = ($sums[$group][$k] ?? 0) + $v;
            }
        }
        $groups = AnimalGroup::whereIn('id', array_keys($sums))->orWhere('is_active', true)->orderBy('code')->get()->keyBy('id');
        $rows = [];
        foreach ($groups as $g) {
            $rows[] = $this->groupRow($g->id, "{$g->code} {$g->name}", $sums[$g->id] ?? []);
        }
        if (isset($sums['ungrouped'])) {
            $rows[] = $this->groupRow(null, 'Animals without a group', $sums['ungrouped']);
        }

        return $rows;
    }

    private function groupRow(?string $id, string $label, array $s): array
    {
        $cost = ($s['inputs'] ?? 0) + ($s['labour'] ?? 0) + ($s['other'] ?? 0);

        return ['id' => $id, 'label' => $label,
            'inputs' => $this->money($s['inputs'] ?? 0), 'labour' => $this->money($s['labour'] ?? 0), 'other' => $this->money($s['other'] ?? 0),
            'cost' => $this->money($cost), 'revenue' => $this->money($s['revenue'] ?? 0), 'margin' => $this->money(($s['revenue'] ?? 0) - $cost)];
    }

    /** @return array<string, array{inputs:int, labour:int, other:int, revenue:int}> cents per cost centre id */
    private function byCenter(string $type, ?string $from, ?string $to): array
    {
        $rows = $this->lines($from, $to)->where('l.cost_center_type', $type)->whereIn('a.type', ['income', 'expense'])
            ->groupBy('l.cost_center_id', 'a.code', 'a.type')
            ->select('l.cost_center_id', 'a.code', 'a.type', DB::raw('SUM(l.debit) AS d'), DB::raw('SUM(l.credit) AS c'))->get();
        $out = [];
        foreach ($rows as $r) {
            $s = &$out[$r->cost_center_id];
            $s ??= ['inputs' => 0, 'labour' => 0, 'other' => 0, 'revenue' => 0];
            if ($r->type === 'income') {
                $s['revenue'] += Money::cents($r->c) - Money::cents($r->d);
            } else {
                $key = match ($r->code) {
                    ChartOfAccounts::INPUTS_USED => 'inputs',
                    ChartOfAccounts::WAGES => 'labour',
                    default => 'other',
                };
                $s[$key] += Money::cents($r->d) - Money::cents($r->c);
            }
            unset($s);
        }

        return $out;
    }

    private function cashBalance(Collection $accounts, string $asOf): int
    {
        $r = DB::table('ledger_lines as l')->join('ledger_entries as e', 'e.id', '=', 'l.entry_id')
            ->where('l.farm_id', $this->context->farmId())->whereIn('l.account_id', $accounts)->whereDate('e.posted_on', '<=', $asOf)
            ->selectRaw('COALESCE(SUM(l.debit), 0) AS d, COALESCE(SUM(l.credit), 0) AS c')->first();

        return Money::cents($r->d) - Money::cents($r->c);
    }

    private function lines(?string $from, ?string $to): Builder
    {
        return DB::table('ledger_lines as l')
            ->join('ledger_entries as e', 'e.id', '=', 'l.entry_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.farm_id', $this->context->farmId())
            ->when($from, fn ($q) => $q->whereDate('e.posted_on', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('e.posted_on', '<=', $to));
    }

    private function money(int $cents): float
    {
        return (float) Money::fromCents($cents);
    }
}
