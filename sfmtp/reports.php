<?php
/*
 * Standard reports with a date range, printable and downloadable as CSV
 * (opens in Excel). Each report declares who may see it; every query is
 * limited to the current farm.
 */
require __DIR__ . '/inc/bootstrap.php';

$farm = require_farm();
$fid = $farm['id'];

/** Report definitions: title, permission, whether it uses the date range, and a builder returning [columns, rows]. Money columns start with '$', numbers with '#'. */
function report_defs(): array
{
    return [
        'cash_flow' => ['Cash flow', 'finance.view', true, 'Money in and out of cash and mobile money, by kind.'],
        'sales_by_customer' => ['Sales by customer', 'sales.view', true, 'Invoices issued in the period, what is paid and what is still due.'],
        'sales_by_product' => ['Sales by product', 'sales.view', true, 'Quantities and amounts invoiced, by line description.'],
        'purchases_by_supplier' => ['Purchases by supplier', 'suppliers.view', true, 'Supplier invoices in the period, paid and outstanding.'],
        'aged_receivables' => ['Customers owe (aged)', 'finance.view', false, 'Open customer invoices by how long they are overdue.'],
        'aged_payables' => ['We owe suppliers (aged)', 'finance.view', false, 'Open supplier invoices by how long they are overdue.'],
        'stock_valuation' => ['Stock on hand and value', 'inventory.values.view', false, 'Every item in every store at average cost.'],
        'labour' => ['Labour', 'workers.view', true, 'Days, hours and verified tasks per worker.'],
        'cost_centres' => ['Costs by crop cycle and animal group', 'finance.view', true, 'Inputs, wages and expenses charged to each cost centre, with harvests and production.'],
        'general_ledger' => ['General ledger', 'finance.view', true, 'Every posting in the period, account by account.'],
        'qr_scans' => ['QR code scans', 'trace.batches.view', true, 'How often each published batch was scanned.'],
    ];
}

function report_build(string $key, string $from, string $to, string $fid): array
{
    $today = farm_today();
    switch ($key) {
        case 'cash_flow':
            $rows = rows("SELECT e.source_type AS kind, SUM(l.debit) AS money_in, SUM(l.credit) AS money_out FROM ledger_lines l JOIN ledger_entries e ON e.id = l.entry_id
                JOIN ledger_accounts a ON a.id = l.account_id WHERE l.farm_id = ? AND a.is_cash = 1 AND e.posted_on BETWEEN ? AND ? GROUP BY e.source_type ORDER BY e.source_type", [$fid, $from, $to]);
            $open = (float) val('SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM ledger_lines l JOIN ledger_entries e ON e.id = l.entry_id JOIN ledger_accounts a ON a.id = l.account_id
                WHERE l.farm_id = ? AND a.is_cash = 1 AND e.posted_on < ?', [$fid, $from]);
            foreach ($rows as &$r) {
                $r['kind'] = label($r['kind']);
                $r['net'] = (float) $r['money_in'] - (float) $r['money_out'];
            }
            unset($r);
            $in = array_sum(array_column($rows, 'money_in'));
            $out = array_sum(array_column($rows, 'money_out'));
            array_unshift($rows, ['kind' => 'Opening balance', 'money_in' => null, 'money_out' => null, 'net' => $open]);
            $rows[] = ['kind' => 'Closing balance', 'money_in' => $in, 'money_out' => $out, 'net' => $open + $in - $out];
            return [['kind' => 'Kind', '$money_in' => 'Money in', '$money_out' => 'Money out', '$net' => 'Net'], $rows];
        case 'sales_by_customer':
            return [['customer' => 'Customer', '#invoices' => 'Invoices', '$amount' => 'Invoiced', '$paid' => 'Paid', '$due' => 'Still due'],
                rows("SELECT c.name AS customer, COUNT(*) AS invoices, SUM(i.amount) AS amount, SUM(i.paid_amount) AS paid, SUM(IF(i.status = 'issued', i.amount - i.paid_amount, 0)) AS due
                    FROM customer_invoices i JOIN customers c ON c.id = i.customer_id WHERE i.farm_id = ? AND i.status IN ('issued','paid') AND i.invoice_date BETWEEN ? AND ?
                    GROUP BY c.id, c.name ORDER BY amount DESC", [$fid, $from, $to])];
        case 'sales_by_product':
            return [['product' => 'Product', 'unit' => 'Unit', '#quantity' => 'Quantity', '$amount' => 'Amount', '$avg_price' => 'Average price'],
                rows("SELECT l.description AS product, l.unit, SUM(l.quantity) AS quantity, SUM(l.amount) AS amount, SUM(l.amount) / NULLIF(SUM(l.quantity), 0) AS avg_price
                    FROM customer_invoice_lines l JOIN customer_invoices i ON i.id = l.invoice_id WHERE i.farm_id = ? AND i.status IN ('issued','paid') AND i.invoice_date BETWEEN ? AND ?
                    GROUP BY l.description, l.unit ORDER BY amount DESC", [$fid, $from, $to])];
        case 'purchases_by_supplier':
            return [['supplier' => 'Supplier', '#invoices' => 'Invoices', '$amount' => 'Invoiced', '$paid' => 'Paid', '$due' => 'Still owed'],
                rows("SELECT s.name AS supplier, COUNT(*) AS invoices, SUM(i.amount) AS amount, SUM(i.paid_amount) AS paid, SUM(IF(i.status = 'recorded', i.amount - i.paid_amount, 0)) AS due
                    FROM supplier_invoices i JOIN suppliers s ON s.id = i.supplier_id WHERE i.farm_id = ? AND i.status <> 'cancelled' AND i.invoice_date BETWEEN ? AND ?
                    GROUP BY s.id, s.name ORDER BY amount DESC", [$fid, $from, $to])];
        case 'aged_receivables':
        case 'aged_payables':
            $recv = $key === 'aged_receivables';
            $src = $recv
                ? rows("SELECT c.name AS who, i.code, i.invoice_date, i.due_on, i.amount - i.paid_amount AS due FROM customer_invoices i JOIN customers c ON c.id = i.customer_id WHERE i.farm_id = ? AND i.status = 'issued'", [$fid])
                : rows("SELECT s.name AS who, i.invoice_number AS code, i.invoice_date, i.due_on, i.amount - i.paid_amount AS due FROM supplier_invoices i JOIN suppliers s ON s.id = i.supplier_id WHERE i.farm_id = ? AND i.status = 'recorded'", [$fid]);
            $by = [];
            foreach ($src as $r) {
                $late = (int) floor((strtotime($today) - strtotime($r['due_on'] ?? $r['invoice_date'])) / 86400);
                $bucket = $late <= 0 ? 'current' : ($late <= 30 ? 'd30' : ($late <= 60 ? 'd60' : ($late <= 90 ? 'd90' : 'older')));
                $by[$r['who']] ??= ['who' => $r['who'], 'current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'older' => 0, 'total' => 0];
                $by[$r['who']][$bucket] += (float) $r['due'];
                $by[$r['who']]['total'] += (float) $r['due'];
            }
            usort($by, fn ($a, $b) => $b['total'] <=> $a['total']);
            return [['who' => $recv ? 'Customer' : 'Supplier', '$current' => 'Not due yet', '$d30' => '1–30 days late', '$d60' => '31–60', '$d90' => '61–90', '$older' => 'Over 90', '$total' => 'Total'], $by];
        case 'stock_valuation':
            return [['item' => 'Item', 'store' => 'Store', 'lot' => 'Lot', 'expires' => 'Expires', '#quantity' => 'Quantity', 'unit' => 'Unit', '$value' => 'Value', '$unit_cost' => 'Average cost'],
                rows('SELECT i.name AS item, l.code AS store, lot.code AS lot, lot.expires_on AS expires, b.quantity, i.unit, b.value, b.value / NULLIF(b.quantity, 0) AS unit_cost
                    FROM stock_balances b JOIN inventory_items i ON i.id = b.item_id JOIN farm_locations l ON l.id = b.location_id LEFT JOIN stock_lots lot ON lot.id = b.lot_id
                    WHERE b.farm_id = ? AND b.quantity <> 0 ORDER BY i.name, l.code', [$fid])];
        case 'labour':
            return [['worker' => 'Worker', 'code' => 'Code', '#days' => 'Days', '#hours' => 'Hours', '#tasks' => 'Tasks verified', '$rate' => 'Daily rate', '$earned' => 'Days × rate'],
                rows("SELECT w.full_name AS worker, w.worker_code AS code,
                    (SELECT COUNT(DISTINCT a.work_date) FROM worker_attendance a WHERE a.worker_id = w.id AND a.work_date BETWEEN ? AND ?) AS days,
                    (SELECT ROUND(COALESCE(SUM(TIMESTAMPDIFF(MINUTE, a.check_in_at, a.check_out_at)), 0) / 60, 1) FROM worker_attendance a WHERE a.worker_id = w.id AND a.work_date BETWEEN ? AND ? AND a.check_out_at IS NOT NULL) AS hours,
                    (SELECT COUNT(*) FROM worker_tasks t WHERE t.worker_id = w.id AND t.status = 'verified' AND DATE(t.verified_at) BETWEEN ? AND ?) AS tasks,
                    w.daily_rate AS rate, w.daily_rate * (SELECT COUNT(DISTINCT a.work_date) FROM worker_attendance a WHERE a.worker_id = w.id AND a.work_date BETWEEN ? AND ?) AS earned
                    FROM workers w WHERE w.farm_id = ? ORDER BY w.full_name", [$from, $to, $from, $to, $from, $to, $from, $to, $fid])];
        case 'cost_centres':
            $rows = rows("SELECT l.cost_center_type AS kind, l.cost_center_id AS cid, SUM(l.debit - l.credit) AS cost FROM ledger_lines l JOIN ledger_entries e ON e.id = l.entry_id
                JOIN ledger_accounts a ON a.id = l.account_id WHERE l.farm_id = ? AND a.type = 'expense' AND l.cost_center_type IS NOT NULL AND e.posted_on BETWEEN ? AND ?
                GROUP BY l.cost_center_type, l.cost_center_id", [$fid, $from, $to]);
            foreach ($rows as &$r) {
                if ($r['kind'] === 'crop_cycle') {
                    $c = row('SELECT c.code, cr.name, c.area_ha FROM crop_cycles c JOIN crops cr ON cr.id = c.crop_id WHERE c.id = ?', [$r['cid']]);
                    $h = row('SELECT SUM(quantity) AS q, MAX(unit) AS u FROM crop_harvests WHERE cycle_id = ? AND harvested_on BETWEEN ? AND ?', [$r['cid'], $from, $to]);
                    $r += ['centre' => trim(($c['code'] ?? '') . ' ' . ($c['name'] ?? '')), 'output' => $h['q'] ? qty($h['q'], $h['u']) : '—',
                        'per_unit' => $h['q'] ? (float) $r['cost'] / (float) $h['q'] : null, 'per_ha' => ($c['area_ha'] ?? 0) > 0 ? (float) $r['cost'] / (float) $c['area_ha'] : null];
                } else {
                    $g = row('SELECT code, name FROM animal_groups WHERE id = ?', [$r['cid']]);
                    $r += ['centre' => trim(($g['code'] ?? '') . ' ' . ($g['name'] ?? '')), 'output' => '—', 'per_unit' => null, 'per_ha' => null];
                }
                $r['kind'] = label($r['kind']);
            }
            unset($r);
            return [['kind' => 'Kind', 'centre' => 'Cost centre', '$cost' => 'Cost', 'output' => 'Harvested', '$per_unit' => 'Cost per unit harvested', '$per_ha' => 'Cost per hectare'], $rows];
        case 'general_ledger':
            return [['posted_on' => 'Date', 'number' => 'Entry', 'account' => 'Account', 'memo' => 'Memo', 'source' => 'From', '$debit' => 'Debit', '$credit' => 'Credit'],
                rows("SELECT e.posted_on, e.number, CONCAT(a.code, ' ', a.name) AS account, COALESCE(l.memo, e.memo) AS memo, e.source_type AS source, l.debit, l.credit
                    FROM ledger_lines l JOIN ledger_entries e ON e.id = l.entry_id JOIN ledger_accounts a ON a.id = l.account_id
                    WHERE l.farm_id = ? AND e.posted_on BETWEEN ? AND ? ORDER BY a.code, e.posted_on, e.number LIMIT 5000", [$fid, $from, $to])];
        case 'qr_scans':
            return [['code' => 'QR code', 'batch' => 'Batch', 'name' => 'Goods', '#scans' => 'Scans', 'last_day' => 'Last scanned'],
                rows('SELECT q.code, b.batch_code AS batch, b.name, SUM(s.scans) AS scans, MAX(s.day) AS last_day FROM trace_qr_scans s JOIN trace_qr_codes q ON q.id = s.qr_code_id
                    JOIN trace_batches b ON b.id = q.batch_id WHERE s.farm_id = ? AND s.day BETWEEN ? AND ? GROUP BY q.id, q.code, b.batch_code, b.name ORDER BY scans DESC', [$fid, $from, $to])];
    }
    return [[], []];
}

$defs = array_filter(report_defs(), fn ($d) => can($d[1]));
$key = input_in('r', array_keys($defs));
$to = input_date('to') ?? farm_today();
$from = input_date('from') ?? date('Y-m-01', strtotime($to));
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

if ($key) {
    [$cols, $data] = report_build($key, $from, $to, $fid);
    [$title, , $ranged] = $defs[$key];
    if (input('format', 5) === 'csv') {
        audit('report.exported', null, ['type' => 'report', 'id' => null], null, ['report' => $key, 'from' => $from, 'to' => $to, 'rows' => count($data)]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9-]+/', '-', strtolower($farm['name'] . '-' . $title)) . ($ranged ? "-$from-to-$to" : '-' . farm_today()) . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_values($cols), ',', '"', '');
        foreach ($data as $r) {
            $line = [];
            foreach (array_keys($cols) as $k) {
                $v = $r[ltrim($k, '$#')] ?? '';
                // Keep spreadsheet programs from running a cell as a formula.
                $line[] = is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
            }
            fputcsv($out, $line, ',', '"', '');
        }
        fclose($out);
        exit;
    }
}

page_start($key ? $defs[$key][0] : 'Reports');
if (!$key) {
    echo '<div class="grid">';
    foreach ($defs as $k => [$t, , , $about]) {
        echo '<a class="card" href="' . e(url('reports.php', ['r' => $k])) . '"><h2>' . e($t) . '</h2><p class="muted">' . e($about) . '</p></a>';
    }
    echo '</div>';
    if (!$defs) {
        echo '<div class="card">No reports for your role.</div>';
    }
    page_end();
    exit;
}
echo '<p class="no-print"><a href="' . e(url('reports.php')) . '">← Reports</a></p>';
echo '<form method="get" class="card row no-print"><input type="hidden" name="r" value="' . e($key) . '">'
    . ($ranged ? field('From', '<input type="date" name="from" value="' . e($from) . '">') . field('To', '<input type="date" name="to" value="' . e($to) . '">') . '<button>Show</button>' : '')
    . '<a class="btn" href="' . e(url('reports.php', ['r' => $key, 'from' => $from, 'to' => $to, 'format' => 'csv'])) . '">Download CSV (Excel)</a><button type="button" data-print>Print</button></form>';
echo '<div class="card"><p class="muted">' . e($farm['name']) . ' · ' . ($ranged ? e(fdate($from)) . ' – ' . e(fdate($to)) : 'as of ' . e(fdate(farm_today()))) . ' · ' . count($data) . ' rows</p>';
$render = [];
foreach ($cols as $k => $h) {
    $field = ltrim($k, '$#');
    $render[($k[0] === '$' || $k[0] === '#' ? '#' : '') . $h] = match ($k[0]) {
        '$' => fn ($r) => $r[$field] === null ? '' : e(money($r[$field])),
        '#' => fn ($r) => e(is_numeric($r[$field] ?? null) ? rtrim(rtrim(number_format((float) $r[$field], 3, '.', ','), '0'), '.') : ($r[$field] ?? '')),
        default => fn ($r) => e($field === 'posted_on' || $field === 'expires' || $field === 'last_day' ? fdate($r[$field]) : ($field === 'source' ? label($r[$field]) : ($r[$field] ?? '—'))),
    };
}
table($data, $render, 'Nothing in this period.');
echo '</div>';
page_end();
