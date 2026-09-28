<?php
/* One dashboard per member: the numbers their role may see, and their own work. */
require __DIR__ . '/inc/bootstrap.php';

$farm = require_farm();
$fid = $farm['id'];
$today = farm_today();
$monthStart = substr($today, 0, 8) . '01';

page_start($farm['name']);
echo '<p class="muted">' . e($farm['code']) . ' · ' . e(trim(($farm['village'] ?? '') . ', ' . ($farm['district'] ?? ''), ', ')) . ' · ' . e(fdate($today)) . '</p>';

$k = [];
if (can('tasks.view') && scope('tasks.view') === 'all') {
    $k[] = kpi('Tasks open', (string) val("SELECT COUNT(*) FROM worker_tasks WHERE farm_id = ? AND status IN ('assigned','in_progress','paused')", [$fid]), null, 'tasks.php');
    $overdue = (int) val("SELECT COUNT(*) FROM worker_tasks WHERE farm_id = ? AND status IN ('assigned','in_progress','paused') AND due_on < ?", [$fid, $today]);
    $k[] = kpi('Overdue tasks', (string) $overdue, $overdue ? 'Past their due date' : null, 'tasks.php');
}
if (can('tasks.verify')) {
    $k[] = kpi('Waiting for verification', (string) val("SELECT COUNT(*) FROM worker_tasks WHERE farm_id = ? AND status = 'submitted'", [$fid]), null, 'tasks.php?tab=verify');
}
if (can('attendance.view')) {
    $k[] = kpi('Checked in today', (string) val('SELECT COUNT(*) FROM worker_attendance WHERE farm_id = ? AND work_date = ?', [$fid, $today]) . ' / ' .
        val("SELECT COUNT(*) FROM workers WHERE farm_id = ? AND status = 'active'", [$fid]), null, 'workers.php?tab=attendance');
}
if (can('crops.operations.view')) {
    $k[] = kpi('Crop cycles growing', (string) val("SELECT COUNT(*) FROM crop_cycles WHERE farm_id = ? AND stage <> 'closed'", [$fid]), null, 'crops.php');
    $pests = (int) val("SELECT COUNT(*) FROM crop_observations WHERE farm_id = ? AND status <> 'resolved' AND kind IN ('pest','disease')", [$fid]);
    $k[] = kpi('Open pest & disease reports', (string) $pests, null, 'crops.php?tab=observations');
}
if (can('livestock.animals.view')) {
    $k[] = kpi('Animals', (string) val("SELECT COUNT(*) FROM animals WHERE farm_id = ? AND status = 'active'", [$fid]), null, 'livestock.php');
    $k[] = kpi('Under withdrawal', (string) val('SELECT COUNT(*) FROM animals WHERE farm_id = ? AND status = \'active\' AND (milk_withdrawal_until >= ? OR meat_withdrawal_until >= ?)', [$fid, $today, $today]),
        'Milk or meat must not be sold', 'livestock.php');
    $milk = val("SELECT COALESCE(SUM(quantity), 0) FROM animal_production_records WHERE farm_id = ? AND product = 'milk' AND produced_on >= ? AND discarded = 0", [$fid, $monthStart]);
    $k[] = kpi('Milk this month', qty($milk, 'L'), null, 'livestock.php?tab=production');
}
if (can('inventory.view')) {
    $low = (int) val('SELECT COUNT(*) FROM inventory_items i WHERE i.farm_id = ? AND i.is_active = 1 AND i.reorder_level IS NOT NULL
        AND (SELECT COALESCE(SUM(quantity), 0) FROM stock_balances b WHERE b.item_id = i.id) <= i.reorder_level', [$fid]);
    $k[] = kpi('Items low on stock', (string) $low, null, 'inventory.php');
    if (can('inventory.values.view')) {
        $k[] = kpi('Stock value', money(val('SELECT COALESCE(SUM(value), 0) FROM stock_balances WHERE farm_id = ?', [$fid])), null, 'inventory.php');
    }
}
if (can('finance.view')) {
    ensure_chart();
    $cash = val('SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM ledger_lines l JOIN ledger_accounts a ON a.id = l.account_id WHERE l.farm_id = ? AND a.is_cash = 1', [$fid]);
    $k[] = kpi('Cash and mobile money', money($cash), null, 'finance.php');
    $pl = row("SELECT COALESCE(SUM(CASE WHEN a.type = 'income' THEN l.credit - l.debit END), 0) AS income, COALESCE(SUM(CASE WHEN a.type = 'expense' THEN l.debit - l.credit END), 0) AS expense
        FROM ledger_lines l JOIN ledger_accounts a ON a.id = l.account_id JOIN ledger_entries e ON e.id = l.entry_id WHERE l.farm_id = ? AND e.posted_on >= ?", [$fid, $monthStart]);
    $k[] = kpi('Profit this month', money($pl['income'] - $pl['expense']), 'Income ' . money($pl['income']) . ' · costs ' . money($pl['expense']), 'finance.php?tab=pl');
    $k[] = kpi('Customers owe', money(val("SELECT COALESCE(SUM(amount - paid_amount), 0) FROM customer_invoices WHERE farm_id = ? AND status = 'issued'", [$fid])), null, 'sales.php');
}
if (can('trace.batches.view')) {
    $k[] = kpi('Open batches', (string) val("SELECT COUNT(*) FROM trace_batches WHERE farm_id = ? AND status = 'open'", [$fid]), null, 'trace.php');
    $k[] = kpi('QR scans (30 days)', (string) val('SELECT COALESCE(SUM(scans), 0) FROM trace_qr_scans WHERE farm_id = ? AND day >= ?', [$fid, date('Y-m-d', strtotime("$today -30 days"))]), null, 'trace.php?tab=qr');
}
if ($k) {
    echo '<div class="kpis">' . implode('', $k) . '</div>';
}

echo '<div class="grid">';

// My tasks (anyone linked to a worker record).
if ($w = my_worker()) {
    $mine = rows("SELECT t.*, a.title FROM worker_tasks t JOIN activities a ON a.id = t.activity_id WHERE t.farm_id = ? AND t.worker_id = ?
        AND t.status IN ('assigned','in_progress','paused','rejected') ORDER BY t.due_on IS NULL, t.due_on", [$fid, $w['id']]);
    echo '<div class="card"><h2>My tasks</h2>';
    table($mine, [
        'Task' => fn ($t) => '<a href="' . e(url('task.php', ['id' => $t['id']])) . '">' . e($t['code']) . '</a> ' . e($t['title']),
        'Due' => fn ($t) => e(fdate($t['due_on'])),
        'Status' => fn ($t) => badge($t['status']),
    ], 'No open tasks. Well done.');
    $in = row('SELECT * FROM worker_attendance WHERE farm_id = ? AND worker_id = ? AND work_date = ?', [$fid, $w['id'], $today]);
    if (can('attendance.record')) {
        echo '<div class="actions">';
        if (!$in) {
            echo post_button('Check in', ['action' => 'check_in'], 'primary', null, url('workers.php'));
        } elseif (!$in['check_out_at']) {
            echo '<span class="muted">Checked in ' . e(fdate($in['check_in_at'], true)) . '</span> ' . post_button('Check out', ['action' => 'check_out'], '', null, url('workers.php'));
        } else {
            echo '<span class="muted">Day done: ' . e(fdate($in['check_in_at'], true)) . ' – ' . e(substr(fdate($in['check_out_at'], true), -5)) . '</span>';
        }
        echo '</div>';
    }
    echo '</div>';
}

if (can('tasks.verify')) {
    $queue = rows("SELECT t.*, a.title, w.full_name FROM worker_tasks t JOIN activities a ON a.id = t.activity_id JOIN workers w ON w.id = t.worker_id
        WHERE t.farm_id = ? AND t.status = 'submitted' ORDER BY t.submitted_at LIMIT 8", [$fid]);
    echo '<div class="card"><h2>Waiting for you to verify</h2>';
    table($queue, [
        'Task' => fn ($t) => '<a href="' . e(url('task.php', ['id' => $t['id']])) . '">' . e($t['code']) . '</a> ' . e($t['title']),
        'Worker' => fn ($t) => e($t['full_name']),
        'Done' => fn ($t) => e(qty($t['quantity'], $t['unit'])),
    ], 'Nothing to verify.');
    echo '</div>';
}

if (can('crops.operations.view')) {
    $obs = rows("SELECT o.*, c.code AS cycle_code FROM crop_observations o JOIN crop_cycles c ON c.id = o.cycle_id WHERE o.farm_id = ? AND o.status <> 'resolved' ORDER BY FIELD(o.severity,'critical','high','medium','low'), o.observed_at DESC LIMIT 6", [$fid]);
    echo '<div class="card"><h2>Field alerts</h2>';
    table($obs, [
        'Report' => fn ($o) => e($o['title']) . '<div class="muted">' . e($o['cycle_code']) . ' · ' . e(label($o['kind'])) . '</div>',
        'Severity' => fn ($o) => '<span class="badge ' . (in_array($o['severity'], ['high', 'critical'], true) ? 'bad' : 'warn') . '">' . e(label($o['severity'])) . '</span>',
    ], 'No open reports.');
    echo '</div>';
}

if (can('livestock.animals.view')) {
    $due = rows("SELECT h.*, a.animal_code, a.name, g.name AS group_name FROM animal_health_records h LEFT JOIN animals a ON a.id = h.animal_id LEFT JOIN animal_groups g ON g.id = h.group_id
        WHERE h.farm_id = ? AND h.next_due_on IS NOT NULL AND h.next_due_on <= ? ORDER BY h.next_due_on LIMIT 6", [$fid, date('Y-m-d', strtotime("$today +14 days"))]);
    echo '<div class="card"><h2>Health care due (14 days)</h2>';
    table($due, [
        'Animal' => fn ($h) => e($h['animal_code'] ? $h['animal_code'] . ' ' . $h['name'] : $h['group_name']),
        'What' => fn ($h) => e(label($h['kind'])) . ($h['product_name'] ? ' · ' . e($h['product_name']) : ''),
        'Due' => fn ($h) => e(fdate($h['next_due_on'])),
    ], 'Nothing due.');
    echo '</div>';
}

if (can('trace.batches.view')) {
    $events = rows('SELECT e.event_type, e.occurred_at, b.batch_code, b.id AS batch_id FROM trace_events e JOIN trace_batches b ON b.id = e.batch_id
        WHERE e.farm_id = ? AND e.event_type NOT IN (\'linked_to\',\'linked_from\') ORDER BY e.farm_seq DESC LIMIT 8', [$fid]);
    echo '<div class="card"><h2>Latest traceability events</h2>';
    table($events, [
        'Event' => fn ($e) => e(label($e['event_type'])),
        'Batch' => fn ($e) => '<a class="code" href="' . e(url('batch.php', ['id' => $e['batch_id']])) . '">' . e($e['batch_code']) . '</a>',
        'When' => fn ($e) => e(fdate($e['occurred_at'])),
    ], 'No events yet.');
    echo '</div>';
}
echo '</div>';
page_end();
