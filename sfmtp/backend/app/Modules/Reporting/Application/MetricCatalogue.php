<?php

namespace App\Modules\Reporting\Application;

use App\Modules\Tenancy\TenantContext;
use App\Support\Http\ApiException;

/**
 * The metric catalogue (docs/05 §4, ADR-0017): every KPI the dashboards
 * show, with what it means, where it comes from and which dashboards use it.
 * One definition serves the dashboards, the catalogue and the metric detail
 * with its series, so a number means the same thing everywhere.
 */
class MetricCatalogue
{
    /** Metrics that take the period without a previous-period comparison. */
    private const PERIOD_ONLY = ['crop.yield_per_ha', 'livestock.mortality_rate', 'livestock.sold'];

    /** The module a metric belongs to, by the prefix of its key. */
    private const MODULES = [
        'farm' => 'farm', 'structure' => 'farm',
        'tasks' => 'workforce', 'activities' => 'workforce', 'workers' => 'workforce', 'attendance' => 'workforce',
        'crop' => 'crop', 'livestock' => 'livestock',
        'inventory' => 'inventory', 'deliveries' => 'inventory',
        'finance' => 'finance', 'payroll' => 'finance', 'budget' => 'finance', 'approvals' => 'finance',
        'trace' => 'traceability',
    ];

    /** @var array<string,string> */
    private const DESCRIPTIONS = [
        'farm.area' => 'Farm size from the farm profile.',
        'farm.members' => 'Members with an active membership of this farm.',
        'structure.plots' => 'Plots mapped in the farm structure.',
        'structure.mapped_area' => 'Total area of mapped plots, from their boundaries.',
        'tasks.today' => 'Tasks scheduled for today that are not finished.',
        'tasks.completed' => 'Tasks verified by a supervisor in the period.',
        'tasks.pending' => 'Tasks submitted by workers and waiting for verification.',
        'tasks.overdue' => 'Tasks past their due date and not submitted.',
        'tasks.done_today' => 'Your own tasks submitted today.',
        'activities.active' => 'Activities (groups of tasks) still open.',
        'workers.present' => 'Workers checked in today.',
        'workers.absent' => 'Active workers who have not checked in today.',
        'attendance.status' => 'Your own attendance today.',
        'crop.active_cycles' => 'Crop cycles not yet closed.',
        'crop.planted_area' => 'Area of cycles in the field (planted to harvesting).',
        'crop.near_harvest' => 'Cycles in the field expected to be harvested in the next 14 days.',
        'crop.expected_yield' => 'Expected yield of open cycles, converted to kilograms.',
        'crop.actual_yield' => 'Harvests recorded in the period, converted to kilograms.',
        'crop.yield_per_ha' => 'Kilograms harvested in the period per hectare of the cycles harvested.',
        'crop.incidents_open' => 'Pest and disease observations not yet resolved.',
        'crop.health_score' => 'Average of the open cycles\' health: each starts at 100 and loses 5, 15, 30 or 50 points per open low, medium, high or critical pest or disease incident.',
        'crop.cost_per_ha' => 'Costs charged to open cycles (inputs, labour, other) divided by their area.',
        'crop.treatments_active' => 'Open cycles still inside a treatment\'s withholding period.',
        'livestock.head_count' => 'Active animals, split by species.',
        'livestock.health_score' => 'Average of the active animals\' health: each starts at 100 and loses 20 for an overdue vaccination or deworming, 25 for losing 5% or more of its weight and 15 for a treatment or injury in the last 30 days.',
        'livestock.new_animals' => 'Animals born or acquired in the period.',
        'livestock.pregnant' => 'Breeding records confirmed pregnant.',
        'livestock.vaccinations_due' => 'Vaccinations and dewormings due in 14 days or overdue.',
        'livestock.under_withdrawal' => 'Active animals whose milk or meat may not be sold yet.',
        'livestock.mortality_rate' => 'Deaths in the period over the herd at risk.',
        'livestock.milk' => 'Milk kept (not discarded) in the period, in litres.',
        'livestock.daily_gain' => 'Average daily gain of animals weighed at least twice in 90 days.',
        'livestock.sold' => 'Animal sales completed in the period.',
        'inventory.items' => 'Active stock items.',
        'inventory.low' => 'Items at or below their reorder level.',
        'inventory.out' => 'Items with no stock left.',
        'inventory.value' => 'Stock on hand at average cost.',
        'inventory.received_today' => 'Stock movements into the stores today.',
        'inventory.issued_today' => 'Stock movements out of the stores today.',
        'inventory.requests_pending' => 'Stock requests waiting to be approved or issued.',
        'deliveries.expected' => 'Sent purchase orders not fully received.',
        'finance.revenue' => 'Income posted to the ledger in the period.',
        'finance.expenses' => 'Expenses posted to the ledger in the period.',
        'finance.net_profit' => 'Revenue less expenses in the period.',
        'finance.cash_balance' => 'Balance of the cash, mobile money and bank accounts.',
        'finance.receivables' => 'Issued customer invoices not yet paid.',
        'finance.payables' => 'Recorded supplier invoices not yet paid.',
        'finance.not_invoiced' => 'Goods received from suppliers without an invoice yet.',
        'payroll.current' => 'Approved payroll not yet paid.',
        'budget.total' => 'Expense budget of the budgets running today.',
        'budget.variance' => 'Running budgets less what was spent against them.',
        'approvals.pending' => 'Expenses, orders and payroll waiting for your approval.',
        'trace.open_batches' => 'Traceability batches still open.',
        'trace.events' => 'Traceability events recorded in the period.',
        'trace.qr_scans' => 'Public scans of the farm\'s QR codes in the period.',
    ];

    public function __construct(
        private readonly DashboardRegistry $registry,
        private readonly TenantContext $context,
    ) {}

    /** @return array<int,array> the metrics this member may see */
    public function list(): array
    {
        $out = [];
        foreach ($this->registry->metricDefinitions() as $key => $def) {
            if ($this->registry->allows($def['permission'])) {
                $out[] = $this->describe($key, $def);
            }
        }

        return $out;
    }

    public function show(string $key, Period $period): array
    {
        $def = $this->registry->metricDefinitions()[$key] ?? throw ApiException::notFound();
        if (! $this->registry->allows($def['permission'])) {
            throw ApiException::forbidden();
        }

        $value = ($def['value'])($period);
        $previous = isset($def['previous']) ? ($def['previous'])($period) : null;
        $timezone = $this->context->farm()->timezone;

        $series = null;
        if ($this->periodBased($key, $def)) {
            $series = array_map(fn (array $b) => [
                'label' => $b['label'],
                'value' => $this->scalar(($def['value'])($b['period'])),
            ], $period->buckets($timezone));
        }

        return $this->describe($key, $def) + [
            'period' => $period->toArray($timezone),
            'value' => $value,
            'previous' => $previous,
            'delta' => isset($def['previous']) ? $this->registry->delta($value, $previous) : null,
            'series' => $series,
        ] + (isset($def['meta']) ? ['meta' => ($def['meta'])($period)] : []);
    }

    private function describe(string $key, array $def): array
    {
        return [
            'key' => $key,
            'label' => $def['label'],
            'module' => self::MODULES[explode('.', $key)[0]] ?? explode('.', $key)[0],
            'format' => $def['format'],
            'description' => self::DESCRIPTIONS[$key] ?? null,
            'period_based' => $this->periodBased($key, $def),
            'dashboards' => $this->registry->dashboardsShowing($key),
        ];
    }

    private function periodBased(string $key, array $def): bool
    {
        return isset($def['previous']) || in_array($key, self::PERIOD_ONLY, true);
    }

    private function scalar(mixed $value): ?float
    {
        if (is_array($value)) {
            $value = $value['value'] ?? $value['amount'] ?? null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
