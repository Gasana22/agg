<?php

namespace App\Modules\Reporting\Application;

use App\Modules\Access\Application\FarmPermissions;
use App\Modules\Catalog\Application\Units;
use App\Modules\Tenancy\TenantContext;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Standard reports (docs/05 §5, ADR-0017). A report is a fixed query with
 * declared parameters and typed columns, so the same definition feeds the
 * on-screen preview and every export format. Each report needs
 * `reports.view` (finance reports `reports.finance.view`) and the
 * permission of the data it reads; a column showing money needs the money
 * permission of its module and is left out otherwise.
 */
class StandardReports
{
    /** Rows shown on screen; exports carry up to EXPORT_LIMIT. */
    public const PREVIEW_LIMIT = 500;

    public const EXPORT_LIMIT = 50000;

    private const FINANCE = 'reports.finance.view|finance.view';

    private const INVENTORY_MONEY = 'inventory.values.view';

    private const SALES_MONEY = 'finance.values.view|sales.invoice|sales.pricing.manage';

    private const PURCHASE_MONEY = 'finance.values.view|procurement.orders.manage';

    public function __construct(
        private readonly TenantContext $context,
        private readonly FarmPermissions $permissions,
        private readonly FinanceReports $finance,
        private readonly Units $units,
    ) {}

    /** @return array<int,array> the reports this member may run, without rows */
    public function list(): array
    {
        $out = [];
        foreach ($this->definitions() as $key => $def) {
            if ($this->allowed($def)) {
                $out[] = $this->describe($key, $def);
            }
        }

        return $out;
    }

    /**
     * Run a report. Unknown reports are 404, reports outside the member's
     * permissions 403, bad parameters 422.
     *
     * @return array{key:string, title:string, group:string, params:array, columns:array, rows:array, totals:?array, row_count:int, truncated:bool, currency:string, generated_at:string}
     */
    public function run(string $key, array $input, int $limit = self::PREVIEW_LIMIT): array
    {
        $def = $this->definitions()[$key] ?? throw ApiException::notFound();
        if (! $this->allowed($def)) {
            throw ApiException::forbidden('report_not_available', 'Your role does not include this report.');
        }

        $params = $this->params($def['params'], $input);
        $columns = array_values(array_filter($def['columns'], fn ($c) => $this->can($c['permission'] ?? null)));
        $keys = array_column($columns, 'key');

        $all = ($def['rows'])($params);
        $rows = array_map(fn (array $r) => array_combine($keys, array_map(fn ($k) => $r[$k] ?? null, $keys)), array_slice($all, 0, $limit));

        $totals = null;
        $summed = array_filter($columns, fn ($c) => $c['total'] ?? false);
        if ($summed !== []) {
            $totals = [];
            foreach ($columns as $c) {
                $totals[$c['key']] = ($c['total'] ?? false) ? $this->sum($all, $c) : null;
            }
        }

        return $this->describe($key, $def) + [
            'params' => $params,
            'columns' => array_map(fn ($c) => ['key' => $c['key'], 'label' => $c['label'], 'type' => $c['type']], $columns),
            'rows' => $rows,
            'totals' => $totals,
            'row_count' => count($all),
            'truncated' => count($all) > $limit,
            'currency' => $this->context->farm()->currency,
            'generated_at' => now()->toIso8601ZuluString(),
        ];
    }

    public function exists(string $key): bool
    {
        return isset($this->definitions()[$key]);
    }

    public function allowedKey(string $key): bool
    {
        $def = $this->definitions()[$key] ?? null;

        return $def !== null && $this->allowed($def);
    }

    /** @return array<string,mixed> validated parameters with defaults filled in */
    public function params(array $spec, array $input): array
    {
        $today = CarbonImmutable::now($this->context->farm()->timezone)->startOfDay();
        $rules = [];
        $defaults = [];
        foreach ($spec as $name) {
            match ($name) {
                'from' => [$rules['from'], $defaults['from']] = [['sometimes', 'date_format:Y-m-d'], $today->subDays(29)->toDateString()],
                'to' => [$rules['to'], $defaults['to']] = [['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'], $today->toDateString()],
                'days' => [$rules['days'], $defaults['days']] = [['sometimes', 'integer', 'min:1', 'max:365'], 60],
            };
        }
        $data = Validator::make(array_intersect_key($input, $rules), $rules)->validate();
        $params = array_merge($defaults, $data);
        if (isset($params['days'])) {
            $params['days'] = (int) $params['days'];
        }
        if (isset($params['from'], $params['to']) && CarbonImmutable::parse($params['from'])->diffInDays(CarbonImmutable::parse($params['to'])) > 366 * 3) {
            throw ApiException::unprocessable('period_too_long', 'A report covers at most three years.');
        }

        return $params;
    }

    public function definition(string $key): array
    {
        return $this->definitions()[$key] ?? throw ApiException::notFound();
    }

    private function describe(string $key, array $def): array
    {
        return ['key' => $key, 'title' => $def['title'], 'group' => $def['group'], 'description' => $def['description'],
            'params' => $def['params'], 'formats' => ['csv', 'xlsx', 'pdf']];
    }

    private function allowed(array $def): bool
    {
        foreach ($def['permission'] as $permission) {
            if (! $this->can($permission)) {
                return false;
            }
        }

        return true;
    }

    private function can(?string $permission): bool
    {
        if ($permission === null) {
            return true;
        }
        foreach (explode('|', $permission) as $alternative) {
            if ($this->permissions->allows($alternative)) {
                return true;
            }
        }

        return false;
    }

    private function sum(array $rows, array $column): string|float|int
    {
        $total = 0.0;
        foreach ($rows as $r) {
            $total += (float) ($r[$column['key']] ?? 0);
        }

        return match ($column['type']) {
            'money' => $this->money($total),
            'integer' => (int) round($total),
            default => $this->number($total, $column['decimals'] ?? 2),
        };
    }

    /**
     * @return array<string, array{title:string, group:string, description:string, permission:array<int,string>, params:array<int,string>, columns:array<int,array>, rows:Closure(array):array}>
     */
    private function definitions(): array
    {
        $col = fn (string $key, string $label, string $type = 'text', array $extra = []) => ['key' => $key, 'label' => $label, 'type' => $type] + $extra;

        return [
            // Inventory
            'stock_valuation' => [
                'title' => 'Stock valuation', 'group' => 'inventory',
                'description' => 'Stock on hand per item with its value at average cost.',
                'permission' => ['reports.view', 'inventory.view', self::INVENTORY_MONEY], 'params' => [],
                'columns' => [$col('code', 'Code'), $col('item', 'Item'), $col('category', 'Category'), $col('quantity', 'On hand', 'number'),
                    $col('unit', 'Unit'), $col('unit_cost', 'Average cost', 'money'), $col('value', 'Value', 'money', ['total' => true])],
                'rows' => fn () => $this->stockValuation(),
            ],
            'stock_movements' => [
                'title' => 'Stock movements', 'group' => 'inventory',
                'description' => 'Every receipt, issue, transfer and adjustment in the period.',
                'permission' => ['reports.view', 'inventory.view'], 'params' => ['from', 'to'],
                'columns' => [$col('occurred_at', 'When', 'datetime'), $col('code', 'Code'), $col('item', 'Item'), $col('type', 'Movement'),
                    $col('quantity', 'Quantity', 'number'), $col('unit', 'Unit'), $col('location', 'Store'),
                    $col('value', 'Value', 'money', ['permission' => self::INVENTORY_MONEY]), $col('note', 'Note')],
                'rows' => fn (array $p) => $this->stockMovements($p),
            ],
            'expiring_lots' => [
                'title' => 'Expiring lots', 'group' => 'inventory',
                'description' => 'Lots with stock left that expire within the chosen number of days, or already expired.',
                'permission' => ['reports.view', 'inventory.view'], 'params' => ['days'],
                'columns' => [$col('expires_on', 'Expires', 'date'), $col('days_left', 'Days left', 'integer'), $col('lot', 'Lot'),
                    $col('lot_number', 'Supplier lot'), $col('item', 'Item'), $col('quantity', 'On hand', 'number'), $col('unit', 'Unit')],
                'rows' => fn (array $p) => $this->expiringLots($p['days']),
            ],
            // Purchasing
            'purchases_by_supplier' => [
                'title' => 'Purchases by supplier', 'group' => 'purchasing',
                'description' => 'Orders sent and invoices recorded per supplier in the period, with what is still owed.',
                'permission' => ['reports.view', 'procurement.orders.manage|procurement.orders.approve|finance.view'], 'params' => ['from', 'to'],
                'columns' => [$col('supplier', 'Supplier'), $col('orders', 'Orders', 'integer', ['total' => true]),
                    $col('ordered', 'Ordered', 'money', ['total' => true, 'permission' => self::PURCHASE_MONEY]),
                    $col('invoices', 'Invoices', 'integer', ['total' => true]),
                    $col('invoiced', 'Invoiced', 'money', ['total' => true, 'permission' => self::PURCHASE_MONEY]),
                    $col('outstanding', 'Still owed', 'money', ['total' => true, 'permission' => self::PURCHASE_MONEY])],
                'rows' => fn (array $p) => $this->purchasesBySupplier($p),
            ],
            'aged_payables' => [
                'title' => 'Aged payables', 'group' => 'finance',
                'description' => 'What is owed to each supplier today, by how long it is overdue.',
                'permission' => [self::FINANCE, 'finance.values.view'], 'params' => [],
                'columns' => $this->agedColumns('supplier', 'Supplier'),
                'rows' => fn () => $this->aged('supplier_invoices', 'suppliers', 'supplier_id', ['recorded']),
            ],
            // Sales
            'sales_by_customer' => [
                'title' => 'Sales by customer', 'group' => 'sales',
                'description' => 'Invoices issued per customer in the period, paid and still due.',
                'permission' => ['reports.view', 'sales.view|finance.view'], 'params' => ['from', 'to'],
                'columns' => [$col('customer', 'Customer'), $col('invoices', 'Invoices', 'integer', ['total' => true]),
                    $col('invoiced', 'Invoiced', 'money', ['total' => true, 'permission' => self::SALES_MONEY]),
                    $col('paid', 'Paid', 'money', ['total' => true, 'permission' => self::SALES_MONEY]),
                    $col('outstanding', 'Still due', 'money', ['total' => true, 'permission' => self::SALES_MONEY])],
                'rows' => fn (array $p) => $this->salesByCustomer($p),
            ],
            'sales_by_product' => [
                'title' => 'Sales by product', 'group' => 'sales',
                'description' => 'Quantities and value ordered per product in the period (approved orders and later).',
                'permission' => ['reports.view', 'sales.view'], 'params' => ['from', 'to'],
                'columns' => [$col('code', 'Code'), $col('product', 'Product'), $col('orders', 'Orders', 'integer', ['total' => true]),
                    $col('quantity', 'Quantity', 'number'), $col('unit', 'Unit'), $col('dispatched', 'Dispatched', 'number'),
                    $col('amount', 'Value', 'money', ['total' => true, 'permission' => self::SALES_MONEY])],
                'rows' => fn (array $p) => $this->salesByProduct($p),
            ],
            'aged_receivables' => [
                'title' => 'Aged receivables', 'group' => 'finance',
                'description' => 'What each customer owes today, by how long it is overdue.',
                'permission' => [self::FINANCE, 'finance.values.view'], 'params' => [],
                'columns' => $this->agedColumns('customer', 'Customer'),
                'rows' => fn () => $this->aged('customer_invoices', 'customers', 'customer_id', ['issued']),
            ],
            // Crops
            'harvests' => [
                'title' => 'Harvests', 'group' => 'crops',
                'description' => 'Every harvest recorded in the period, with the quantity in kilograms where it converts.',
                'permission' => ['reports.view', 'crops.harvest.view'], 'params' => ['from', 'to'],
                'columns' => [$col('harvested_on', 'Date', 'date'), $col('cycle', 'Cycle'), $col('crop', 'Crop'), $col('plot', 'Plot'),
                    $col('quantity', 'Quantity', 'number'), $col('unit', 'Unit'), $col('kg', 'Kilograms', 'number', ['total' => true, 'decimals' => 1]),
                    $col('grade', 'Grade')],
                'rows' => fn (array $p) => $this->harvests($p),
            ],
            'crop_operations' => [
                'title' => 'Field work', 'group' => 'crops',
                'description' => 'Crop operations in the period with their status, labour and cost.',
                'permission' => ['reports.view', 'crops.operations.view'], 'params' => ['from', 'to'],
                'columns' => [$col('occurred_at', 'When', 'datetime'), $col('cycle', 'Cycle'), $col('plot', 'Plot'), $col('type', 'Operation'),
                    $col('status', 'Status'), $col('labour_hours', 'Labour (h)', 'number', ['total' => true]),
                    $col('cost', 'Cost', 'money', ['total' => true, 'permission' => 'finance.values.view'])],
                'rows' => fn (array $p) => $this->cropOperations($p),
            ],
            'cost_per_crop' => [
                'title' => 'Cost per crop cycle', 'group' => 'finance',
                'description' => 'Inputs, labour and other costs, revenue and margin per crop cycle, per hectare and per kilogram.',
                'permission' => [self::FINANCE], 'params' => ['from', 'to'],
                'columns' => [$col('code', 'Cycle'), $col('crop', 'Crop'), $col('plot', 'Plot'), $col('stage', 'Stage'), $col('area_ha', 'Area (ha)', 'number'),
                    $col('harvested_kg', 'Harvested (kg)', 'number', ['decimals' => 1]),
                    $col('inputs', 'Inputs', 'money', ['total' => true]), $col('labour', 'Labour', 'money', ['total' => true]), $col('other', 'Other', 'money', ['total' => true]),
                    $col('cost', 'Cost', 'money', ['total' => true]), $col('revenue', 'Revenue', 'money', ['total' => true]), $col('margin', 'Margin', 'money', ['total' => true]),
                    $col('cost_per_ha', 'Cost / ha', 'money'), $col('cost_per_kg', 'Cost / kg', 'money')],
                'rows' => fn (array $p) => array_map(fn ($r) => array_merge(array_map(fn ($v) => is_float($v) ? $this->money($v) : $v, $r),
                    ['area_ha' => $this->number($r['area_ha'], 4), 'harvested_kg' => $this->number($r['harvested_kg'], 1)]),
                    $this->finance->cropCycles($p['from'], $p['to'])),
            ],
            // Livestock
            'milk_production' => [
                'title' => 'Milk production', 'group' => 'livestock',
                'description' => 'Milk per day in litres: kept, and discarded (withdrawal or quality).',
                'permission' => ['reports.view', 'livestock.animals.view'], 'params' => ['from', 'to'],
                'columns' => [$col('date', 'Date', 'date'), $col('records', 'Records', 'integer', ['total' => true]),
                    $col('kept', 'Kept (l)', 'number', ['total' => true, 'decimals' => 1]), $col('discarded', 'Discarded (l)', 'number', ['total' => true, 'decimals' => 1])],
                'rows' => fn (array $p) => $this->milk($p),
            ],
            'animal_health' => [
                'title' => 'Animal health records', 'group' => 'livestock',
                'description' => 'Treatments, vaccinations, dewormings and checks in the period.',
                'permission' => ['reports.view', 'livestock.animals.view'], 'params' => ['from', 'to'],
                'columns' => [$col('given_on', 'Date', 'date'), $col('subject', 'Animal or group'), $col('kind', 'Kind'), $col('diagnosis', 'Diagnosis'),
                    $col('product', 'Product'), $col('dose', 'Dose'), $col('next_due_on', 'Next due', 'date'), $col('given_by', 'Given by')],
                'rows' => fn (array $p) => $this->animalHealth($p),
            ],
            'cost_per_animal_group' => [
                'title' => 'Cost per animal group', 'group' => 'finance',
                'description' => 'Inputs, labour and other costs, revenue and margin per animal group.',
                'permission' => [self::FINANCE], 'params' => ['from', 'to'],
                'columns' => [$col('label', 'Group'),
                    $col('inputs', 'Inputs', 'money', ['total' => true]), $col('labour', 'Labour', 'money', ['total' => true]), $col('other', 'Other', 'money', ['total' => true]),
                    $col('cost', 'Cost', 'money', ['total' => true]), $col('revenue', 'Revenue', 'money', ['total' => true]), $col('margin', 'Margin', 'money', ['total' => true])],
                'rows' => fn (array $p) => array_map(fn ($r) => array_map(fn ($v) => is_float($v) ? $this->money($v) : $v, $r), $this->finance->animalGroups($p['from'], $p['to'])),
            ],
            // Workforce
            'task_completion' => [
                'title' => 'Task completion', 'group' => 'workforce',
                'description' => 'Per worker: tasks due in the period, verified, sent back, still open and overdue, with hours worked.',
                'permission' => ['reports.view', 'tasks.view'], 'params' => ['from', 'to'],
                'columns' => [$col('worker', 'Worker'), $col('assigned', 'Due', 'integer', ['total' => true]), $col('verified', 'Verified', 'integer', ['total' => true]),
                    $col('rejected', 'Sent back', 'integer', ['total' => true]), $col('open', 'Open', 'integer', ['total' => true]),
                    $col('overdue', 'Overdue', 'integer', ['total' => true]), $col('hours', 'Hours', 'number', ['total' => true]),
                    $col('completion', 'Verified share', 'percent')],
                'rows' => fn (array $p) => $this->taskCompletion($p),
            ],
            'attendance' => [
                'title' => 'Attendance', 'group' => 'workforce',
                'description' => 'Per worker: days checked in and hours between check-in and check-out.',
                'permission' => ['reports.view', 'attendance.view|attendance.approve'], 'params' => ['from', 'to'],
                'columns' => [$col('code', 'Code'), $col('worker', 'Worker'), $col('days', 'Days present', 'integer', ['total' => true]),
                    $col('hours', 'Hours', 'number', ['total' => true]), $col('open', 'Not checked out', 'integer', ['total' => true])],
                'rows' => fn (array $p) => $this->attendance($p),
            ],
            // Finance
            'profit_and_loss' => [
                'title' => 'Profit and loss', 'group' => 'finance',
                'description' => 'Income and expenses per account in the period.',
                'permission' => [self::FINANCE], 'params' => ['from', 'to'],
                'columns' => [$col('section', 'Section'), $col('code', 'Account'), $col('name', 'Name'), $col('amount', 'Amount', 'money')],
                'rows' => fn (array $p) => $this->profitAndLoss($p),
            ],
            'cash_flow' => [
                'title' => 'Cash flow', 'group' => 'finance',
                'description' => 'Money into and out of the cash, mobile money and bank accounts, by kind of document.',
                'permission' => [self::FINANCE], 'params' => ['from', 'to'],
                'columns' => [$col('section', 'Section'), $col('source', 'Source'), $col('amount', 'Amount', 'money')],
                'rows' => fn (array $p) => $this->cashFlow($p),
            ],
            // Traceability
            'trace_batches' => [
                'title' => 'Traceability batches', 'group' => 'traceability',
                'description' => 'Batches created in the period with their kind, quantity and status.',
                'permission' => ['reports.view', 'trace.batches.view'], 'params' => ['from', 'to'],
                'columns' => [$col('created_at', 'Created', 'datetime'), $col('batch_code', 'Batch'), $col('kind', 'Kind'), $col('name', 'Name'),
                    $col('quantity', 'Quantity', 'number'), $col('unit', 'Unit'), $col('status', 'Status')],
                'rows' => fn (array $p) => $this->traceBatches($p),
            ],
        ];
    }

    private function agedColumns(string $key, string $label): array
    {
        $money = fn (string $k, string $l) => ['key' => $k, 'label' => $l, 'type' => 'money', 'total' => true];

        return [['key' => $key, 'label' => $label, 'type' => 'text'], ['key' => 'invoices', 'label' => 'Invoices', 'type' => 'integer', 'total' => true],
            $money('current', 'Not yet due'), $money('d1_30', '1–30 days'), $money('d31_60', '31–60 days'), $money('d61_90', '61–90 days'),
            $money('d90_plus', 'Over 90 days'), $money('total', 'Total')];
    }

    // ---- Queries -------------------------------------------------------

    private function table(string $table): Builder
    {
        return DB::table($table)->where("{$table}.farm_id", $this->context->farmId());
    }

    /** @return array{0:string,1:string} UTC bounds of the local date range */
    private function instants(array $p): array
    {
        $tz = $this->context->farm()->timezone;

        return [CarbonImmutable::parse($p['from'], $tz)->startOfDay()->utc()->toDateTimeString(),
            CarbonImmutable::parse($p['to'], $tz)->endOfDay()->utc()->format('Y-m-d H:i:s.u')];
    }

    private function local(mixed $timestamp): ?string
    {
        return $timestamp === null ? null : CarbonImmutable::parse($timestamp, 'UTC')->setTimezone($this->context->farm()->timezone)->format('Y-m-d H:i');
    }

    private function stockValuation(): array
    {
        return $this->table('stock_balances')
            ->join('inventory_items as i', 'i.id', '=', 'stock_balances.item_id')
            ->leftJoin('global_inventory_categories as c', 'c.id', '=', 'i.category_id')
            ->groupBy('i.id', 'i.code', 'i.name', 'i.unit', 'c.name')
            ->havingRaw('SUM(stock_balances.quantity) <> 0')
            ->orderBy('i.code')
            ->select('i.code', 'i.name', 'i.unit', 'c.name as category', DB::raw('SUM(stock_balances.quantity) AS qty'), DB::raw('SUM(stock_balances.value) AS val'))
            ->get()
            ->map(fn ($r) => ['code' => $r->code, 'item' => $r->name, 'category' => $r->category, 'quantity' => $this->number($r->qty, 3), 'unit' => $r->unit,
                'unit_cost' => (float) $r->qty > 0 ? $this->money((float) $r->val / (float) $r->qty) : null, 'value' => $this->money($r->val)])
            ->all();
    }

    private function stockMovements(array $p): array
    {
        [$from, $to] = $this->instants($p);

        return $this->table('stock_movements')
            ->join('inventory_items as i', 'i.id', '=', 'stock_movements.item_id')
            ->leftJoin('farm_locations as l', 'l.id', '=', 'stock_movements.location_id')
            ->whereBetween('stock_movements.occurred_at', [$from, $to])
            ->orderBy('stock_movements.occurred_at')->orderBy('stock_movements.id')
            ->limit(self::EXPORT_LIMIT + 1)
            ->select('stock_movements.occurred_at', 'i.code', 'i.name', 'i.unit', 'stock_movements.type', 'stock_movements.quantity', 'stock_movements.value', 'stock_movements.note', 'l.name as location')
            ->get()
            ->map(fn ($r) => ['occurred_at' => $this->local($r->occurred_at), 'code' => $r->code, 'item' => $r->name, 'type' => str_replace('_', ' ', $r->type),
                'quantity' => $this->number($r->quantity, 3), 'unit' => $r->unit, 'location' => $r->location, 'value' => $r->value === null ? null : $this->money($r->value), 'note' => $r->note])
            ->all();
    }

    private function expiringLots(int $days): array
    {
        $today = CarbonImmutable::now($this->context->farm()->timezone)->startOfDay();

        return $this->table('stock_lots')
            ->join('inventory_items as i', 'i.id', '=', 'stock_lots.item_id')
            ->join('stock_balances as b', fn ($j) => $j->on('b.lot_id', '=', 'stock_lots.id')->on('b.farm_id', '=', 'stock_lots.farm_id'))
            ->whereNotNull('stock_lots.expires_on')
            ->where('stock_lots.expires_on', '<=', $today->addDays($days)->toDateString())
            ->groupBy('stock_lots.id', 'stock_lots.code', 'stock_lots.lot_number', 'stock_lots.expires_on', 'i.name', 'i.unit')
            ->havingRaw('SUM(b.quantity) > 0')
            ->orderBy('stock_lots.expires_on')->orderBy('stock_lots.code')
            ->select('stock_lots.code', 'stock_lots.lot_number', 'stock_lots.expires_on', 'i.name', 'i.unit', DB::raw('SUM(b.quantity) AS qty'))
            ->get()
            ->map(function ($r) use ($today) {
                $expires = CarbonImmutable::parse($r->expires_on, $this->context->farm()->timezone)->startOfDay();

                return ['expires_on' => $expires->toDateString(), 'days_left' => (int) $today->diffInDays($expires, false), 'lot' => $r->code,
                    'lot_number' => $r->lot_number, 'item' => $r->name, 'quantity' => $this->number($r->qty, 3), 'unit' => $r->unit];
            })
            ->all();
    }

    private function purchasesBySupplier(array $p): array
    {
        [$from, $to] = $this->instants($p);
        $orders = $this->table('purchase_orders')->whereNotIn('status', ['draft', 'cancelled'])
            ->whereBetween(DB::raw('COALESCE(sent_at, approved_at, created_at)'), [$from, $to])
            ->groupBy('supplier_id')->select('supplier_id', DB::raw('COUNT(*) AS n'), DB::raw('SUM(total_amount) AS amount'))->get()->keyBy('supplier_id');
        $invoices = $this->table('supplier_invoices')->where('status', '!=', 'cancelled')->whereBetween('invoice_date', [$p['from'], $p['to']])
            ->groupBy('supplier_id')->select('supplier_id', DB::raw('COUNT(*) AS n'), DB::raw('SUM(amount) AS amount'), DB::raw('SUM(amount - paid_amount) AS open'))->get()->keyBy('supplier_id');

        return $this->table('suppliers')->whereIn('id', $orders->keys()->merge($invoices->keys())->unique()->values())->orderBy('name')->get(['id', 'code', 'name'])
            ->map(fn ($s) => ['supplier' => "{$s->name} ({$s->code})",
                'orders' => (int) ($orders[$s->id]->n ?? 0), 'ordered' => $this->money($orders[$s->id]->amount ?? 0),
                'invoices' => (int) ($invoices[$s->id]->n ?? 0), 'invoiced' => $this->money($invoices[$s->id]->amount ?? 0),
                'outstanding' => $this->money($invoices[$s->id]->open ?? 0)])
            ->all();
    }

    private function salesByCustomer(array $p): array
    {
        return $this->table('customer_invoices')
            ->join('customers as c', 'c.id', '=', 'customer_invoices.customer_id')
            ->whereIn('customer_invoices.status', ['issued', 'paid'])
            ->whereBetween('customer_invoices.invoice_date', [$p['from'], $p['to']])
            ->groupBy('c.id', 'c.code', 'c.name')->orderBy('c.name')
            ->select('c.code', 'c.name', DB::raw('COUNT(*) AS n'), DB::raw('SUM(customer_invoices.amount) AS amount'), DB::raw('SUM(customer_invoices.paid_amount) AS paid'))
            ->get()
            ->map(fn ($r) => ['customer' => "{$r->name} ({$r->code})", 'invoices' => (int) $r->n, 'invoiced' => $this->money($r->amount),
                'paid' => $this->money($r->paid), 'outstanding' => $this->money((float) $r->amount - (float) $r->paid)])
            ->all();
    }

    private function salesByProduct(array $p): array
    {
        [$from, $to] = $this->instants($p);

        return $this->table('sales_order_lines')
            ->join('sales_orders as o', 'o.id', '=', 'sales_order_lines.order_id')
            ->join('products as pr', 'pr.id', '=', 'sales_order_lines.product_id')
            ->whereIn('o.status', ['approved', 'invoiced', 'dispatched', 'delivered'])
            ->whereBetween('o.created_at', [$from, $to])
            ->groupBy('pr.id', 'pr.code', 'pr.name', 'sales_order_lines.unit')->orderBy('pr.code')
            ->select('pr.code', 'pr.name', 'sales_order_lines.unit', DB::raw('COUNT(DISTINCT o.id) AS n'), DB::raw('SUM(sales_order_lines.quantity) AS qty'),
                DB::raw('SUM(sales_order_lines.dispatched_quantity) AS sent'), DB::raw('SUM(sales_order_lines.amount) AS amount'))
            ->get()
            ->map(fn ($r) => ['code' => $r->code, 'product' => $r->name, 'orders' => (int) $r->n, 'quantity' => $this->number($r->qty, 3), 'unit' => $r->unit,
                'dispatched' => $this->number($r->sent, 3), 'amount' => $this->money($r->amount)])
            ->all();
    }

    /** Open balances per counterparty, bucketed by days past the due date. */
    private function aged(string $table, string $parties, string $fk, array $statuses): array
    {
        $today = CarbonImmutable::now($this->context->farm()->timezone)->startOfDay();
        $rows = [];
        $this->table($table)->join("{$parties} as p", 'p.id', '=', "{$table}.{$fk}")
            ->whereIn("{$table}.status", $statuses)->whereColumn("{$table}.paid_amount", '<', "{$table}.amount")
            ->orderBy('p.name')
            ->get(['p.id', 'p.code', 'p.name', "{$table}.due_on", "{$table}.invoice_date", "{$table}.amount", "{$table}.paid_amount"])
            ->each(function ($r) use (&$rows, $today) {
                $open = (float) $r->amount - (float) $r->paid_amount;
                $due = CarbonImmutable::parse($r->due_on ?? $r->invoice_date, $this->context->farm()->timezone)->startOfDay();
                $late = (int) $due->diffInDays($today, false);
                $bucket = match (true) {
                    $late <= 0 => 'current',
                    $late <= 30 => 'd1_30',
                    $late <= 60 => 'd31_60',
                    $late <= 90 => 'd61_90',
                    default => 'd90_plus',
                };
                $row = &$rows[$r->id];
                $row ??= ['party' => "{$r->name} ({$r->code})", 'invoices' => 0, 'current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90_plus' => 0.0, 'total' => 0.0];
                $row['invoices']++;
                $row[$bucket] += $open;
                $row['total'] += $open;
                unset($row);
            });

        $key = $parties === 'customers' ? 'customer' : 'supplier';

        return array_values(array_map(fn ($r) => [$key => $r['party'], 'invoices' => $r['invoices']]
            + array_map(fn ($v) => $this->money($v), array_intersect_key($r, array_flip(['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus', 'total']))), $rows));
    }

    private function harvests(array $p): array
    {
        return $this->table('crop_harvests')
            ->join('crop_cycles as cy', 'cy.id', '=', 'crop_harvests.cycle_id')
            ->join('crops as c', 'c.id', '=', 'cy.crop_id')
            ->join('farm_plots as pl', 'pl.id', '=', 'cy.plot_id')
            ->whereBetween('crop_harvests.harvested_on', [$p['from'], $p['to']])
            ->orderBy('crop_harvests.harvested_on')->orderBy('cy.code')
            ->get(['crop_harvests.harvested_on', 'cy.code', 'c.name as crop', 'c.variety', 'pl.code as plot', 'crop_harvests.quantity', 'crop_harvests.unit', 'crop_harvests.quality_grade'])
            ->map(fn ($r) => ['harvested_on' => substr((string) $r->harvested_on, 0, 10), 'cycle' => $r->code, 'crop' => trim($r->crop.($r->variety ? " ({$r->variety})" : '')),
                'plot' => $r->plot, 'quantity' => $this->number($r->quantity, 3), 'unit' => $r->unit,
                'kg' => ($kg = $this->units->toKg((float) $r->quantity, $r->unit)) === null ? null : $this->number($kg, 1), 'grade' => $r->quality_grade])
            ->all();
    }

    private function cropOperations(array $p): array
    {
        [$from, $to] = $this->instants($p);

        return $this->table('crop_operations')
            ->join('crop_cycles as cy', 'cy.id', '=', 'crop_operations.cycle_id')
            ->join('farm_plots as pl', 'pl.id', '=', 'cy.plot_id')
            ->whereBetween('crop_operations.occurred_at', [$from, $to])
            ->orderBy('crop_operations.occurred_at')
            ->limit(self::EXPORT_LIMIT + 1)
            ->get(['crop_operations.occurred_at', 'cy.code', 'pl.code as plot', 'crop_operations.type', 'crop_operations.status', 'crop_operations.labour_hours', 'crop_operations.cost_amount'])
            ->map(fn ($r) => ['occurred_at' => $this->local($r->occurred_at), 'cycle' => $r->code, 'plot' => $r->plot, 'type' => str_replace('_', ' ', $r->type),
                'status' => $r->status, 'labour_hours' => $r->labour_hours === null ? null : $this->number($r->labour_hours),
                'cost' => $r->cost_amount === null ? null : $this->money($r->cost_amount)])
            ->all();
    }

    private function milk(array $p): array
    {
        $by = [];
        $this->table('animal_production_records')->where('product', 'milk')
            ->whereNotExists(fn ($q) => $q->from('animal_record_voids as v')->where('v.record_type', 'production')->whereColumn('v.record_id', 'animal_production_records.id'))
            ->whereBetween('produced_on', [$p['from'], $p['to']])
            ->get(['produced_on', 'quantity', 'unit', 'discarded'])
            ->each(function ($r) use (&$by) {
                $day = substr((string) $r->produced_on, 0, 10);
                $litres = $this->units->convert((float) $r->quantity, $r->unit, 'l') ?? 0;
                $by[$day] ??= ['records' => 0, 'kept' => 0.0, 'discarded' => 0.0];
                $by[$day]['records']++;
                $by[$day][$r->discarded ? 'discarded' : 'kept'] += $litres;
            });
        ksort($by);

        return array_map(fn ($day, $v) => ['date' => $day, 'records' => $v['records'], 'kept' => $this->number($v['kept'], 1), 'discarded' => $this->number($v['discarded'], 1)],
            array_keys($by), $by);
    }

    private function animalHealth(array $p): array
    {
        return $this->table('animal_health_records')
            ->leftJoin('animals as a', 'a.id', '=', 'animal_health_records.animal_id')
            ->leftJoin('animal_groups as g', 'g.id', '=', 'animal_health_records.group_id')
            ->whereNotExists(fn ($q) => $q->from('animal_record_voids as v')->where('v.record_type', 'health')->whereColumn('v.record_id', 'animal_health_records.id'))
            ->whereBetween('animal_health_records.given_on', [$p['from'], $p['to']])
            ->orderBy('animal_health_records.given_on')
            ->get(['animal_health_records.given_on', 'a.animal_code', 'a.name as animal_name', 'g.name as group_name', 'animal_health_records.kind', 'animal_health_records.diagnosis',
                'animal_health_records.product_name', 'animal_health_records.dose', 'animal_health_records.dose_unit', 'animal_health_records.next_due_on', 'animal_health_records.given_by'])
            ->map(fn ($r) => ['given_on' => substr((string) $r->given_on, 0, 10),
                'subject' => $r->animal_code ? trim($r->animal_code.($r->animal_name ? " {$r->animal_name}" : '')) : "Group {$r->group_name}",
                'kind' => $r->kind, 'diagnosis' => $r->diagnosis, 'product' => $r->product_name,
                'dose' => $r->dose === null ? null : trim($this->number($r->dose, 3).' '.$r->dose_unit),
                'next_due_on' => $r->next_due_on === null ? null : substr((string) $r->next_due_on, 0, 10), 'given_by' => $r->given_by])
            ->all();
    }

    private function taskCompletion(array $p): array
    {
        $today = CarbonImmutable::now($this->context->farm()->timezone)->toDateString();
        $open = ['assigned', 'in_progress', 'paused', 'submitted'];

        return $this->table('worker_tasks')
            ->join('workers as w', 'w.id', '=', 'worker_tasks.worker_id')
            ->whereBetween('worker_tasks.due_on', [$p['from'], $p['to']])
            ->where('worker_tasks.status', '!=', 'cancelled')
            ->groupBy('w.id', 'w.worker_code', 'w.full_name')->orderBy('w.full_name')
            ->select('w.worker_code', 'w.full_name', DB::raw('COUNT(*) AS n'),
                DB::raw("SUM(CASE WHEN worker_tasks.status = 'verified' THEN 1 ELSE 0 END) AS verified"),
                DB::raw("SUM(CASE WHEN worker_tasks.status = 'rejected' THEN 1 ELSE 0 END) AS rejected"),
                DB::raw("SUM(CASE WHEN worker_tasks.status IN ('".implode("','", $open)."') THEN 1 ELSE 0 END) AS open_n"),
                DB::raw("SUM(CASE WHEN worker_tasks.status IN ('assigned','in_progress','paused') AND worker_tasks.due_on < '{$today}' THEN 1 ELSE 0 END) AS overdue"),
                DB::raw('COALESCE(SUM(worker_tasks.worked_minutes), 0) AS minutes'))
            ->get()
            ->map(fn ($r) => ['worker' => "{$r->full_name} ({$r->worker_code})", 'assigned' => (int) $r->n, 'verified' => (int) $r->verified,
                'rejected' => (int) $r->rejected, 'open' => (int) $r->open_n, 'overdue' => (int) $r->overdue,
                'hours' => $this->number((int) $r->minutes / 60, 1), 'completion' => $r->n > 0 ? round($r->verified / $r->n, 4) : null])
            ->all();
    }

    private function attendance(array $p): array
    {
        $rows = [];
        $this->table('worker_attendance')->join('workers as w', 'w.id', '=', 'worker_attendance.worker_id')
            ->whereBetween('worker_attendance.work_date', [$p['from'], $p['to']])
            ->orderBy('w.full_name')
            ->get(['w.id', 'w.worker_code', 'w.full_name', 'worker_attendance.check_in_at', 'worker_attendance.check_out_at'])
            ->each(function ($r) use (&$rows) {
                $row = &$rows[$r->id];
                $row ??= ['code' => $r->worker_code, 'worker' => $r->full_name, 'days' => 0, 'hours' => 0.0, 'open' => 0];
                $row['days']++;
                if ($r->check_out_at === null) {
                    $row['open']++;
                } else {
                    $row['hours'] += CarbonImmutable::parse($r->check_in_at)->diffInMinutes(CarbonImmutable::parse($r->check_out_at)) / 60;
                }
                unset($row);
            });

        return array_values(array_map(fn ($r) => ['hours' => $this->number($r['hours'], 1)] + $r, $rows));
    }

    private function profitAndLoss(array $p): array
    {
        $r = $this->finance->profitAndLoss($p['from'], $p['to']);
        $line = fn (string $section, array $a) => ['section' => $section, 'code' => $a['code'], 'name' => $a['name'], 'amount' => $this->money($a['amount'])];

        return [
            ...array_map(fn ($a) => $line('Income', $a), $r['income']),
            ['section' => 'Income', 'code' => null, 'name' => 'Total income', 'amount' => $this->money($r['totals']['income'])],
            ...array_map(fn ($a) => $line('Expenses', $a), $r['expenses']),
            ['section' => 'Expenses', 'code' => null, 'name' => 'Total expenses', 'amount' => $this->money($r['totals']['expenses'])],
            ['section' => 'Result', 'code' => null, 'name' => 'Net profit', 'amount' => $this->money($r['totals']['net'])],
        ];
    }

    private function cashFlow(array $p): array
    {
        $r = $this->finance->cashFlow($p['from'], $p['to']);
        $label = fn (?string $s) => $s === null ? 'Other' : ucfirst(str_replace('_', ' ', $s));

        return [
            ['section' => 'Opening', 'source' => 'Opening balance', 'amount' => $this->money($r['opening'])],
            ...collect($r['inflows'])->map(fn ($i) => ['section' => 'In', 'source' => $label($i['source']), 'amount' => $this->money($i['amount'])])->all(),
            ...collect($r['outflows'])->map(fn ($i) => ['section' => 'Out', 'source' => $label($i['source']), 'amount' => $this->money(-$i['amount'])])->all(),
            ['section' => 'Net', 'source' => 'Net change', 'amount' => $this->money($r['net'])],
            ['section' => 'Closing', 'source' => 'Closing balance', 'amount' => $this->money($r['closing'])],
        ];
    }

    private function traceBatches(array $p): array
    {
        [$from, $to] = $this->instants($p);

        return $this->table('trace_batches')->whereBetween('created_at', [$from, $to])->orderBy('created_at')->orderBy('batch_code')
            ->limit(self::EXPORT_LIMIT + 1)
            ->get(['created_at', 'batch_code', 'kind', 'name', 'quantity', 'unit', 'status'])
            ->map(fn ($r) => ['created_at' => $this->local($r->created_at), 'batch_code' => $r->batch_code, 'kind' => str_replace('_', ' ', $r->kind), 'name' => $r->name,
                'quantity' => $r->quantity === null ? null : $this->number($r->quantity, 3), 'unit' => $r->unit, 'status' => $r->status])
            ->all();
    }

    private function money(mixed $amount): string
    {
        return number_format(round((float) $amount, 2), 2, '.', '');
    }

    private function number(mixed $value, int $decimals = 2): string
    {
        $s = number_format(round((float) $value, $decimals), $decimals, '.', '');

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
