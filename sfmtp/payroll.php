<?php
/*
 * Payroll from attendance: each worker with a daily rate is paid for the days
 * they checked in during the period, plus a bonus, less deductions.
 *
 * draft → approved → paid (or cancelled while a draft).
 * Approval (never by whoever prepared it, unless the owner) posts
 * Dr 5300 Wages / Cr 2200 Wages payable (net) and Cr 2210 Payroll deductions.
 * Payments clear wages payable: Dr 2200 / Cr cash or mobile money.
 */
require __DIR__ . '/inc/bootstrap.php';

$farm = require_farm();
$fid = $farm['id'];
(can('finance.payroll.manage') || can('finance.payroll.approve') || can('finance.payroll.view_hours')) || require_can('finance.payroll.manage');
$money = can('finance.payroll.manage') || can('finance.payroll.approve') || can('finance.view');
$run = input_id('id') ? farm_row('payroll_runs', input_id('id')) : null;

/** Rebuild a draft's lines from attendance and verified tasks, keeping bonuses, deductions and notes. */
function payroll_compute(array $run): void
{
    $kept = [];
    foreach (rows('SELECT worker_id, bonus, deductions, note FROM payroll_lines WHERE run_id = ?', [$run['id']]) as $l) {
        $kept[$l['worker_id']] = $l;
    }
    q('DELETE FROM payroll_lines WHERE run_id = ? AND farm_id = ?', [$run['id'], farm_id()]);
    $workers = rows("SELECT w.id, w.daily_rate,
            (SELECT COUNT(DISTINCT a.work_date) FROM worker_attendance a WHERE a.worker_id = w.id AND a.work_date BETWEEN ? AND ?) AS days,
            (SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, a.check_in_at, a.check_out_at)), 0) FROM worker_attendance a WHERE a.worker_id = w.id AND a.work_date BETWEEN ? AND ? AND a.check_out_at IS NOT NULL) AS minutes,
            (SELECT COUNT(*) FROM worker_tasks t WHERE t.worker_id = w.id AND t.status = 'verified' AND DATE(t.verified_at) BETWEEN ? AND ?) AS tasks
        FROM workers w WHERE w.farm_id = ? AND w.daily_rate IS NOT NULL AND w.daily_rate > 0 AND (w.left_on IS NULL OR w.left_on >= ?)",
        [$run['period_start'], $run['period_end'], $run['period_start'], $run['period_end'], $run['period_start'], $run['period_end'], farm_id(), $run['period_start']]);
    $gross = $ded = 0.0;
    foreach ($workers as $w) {
        $k = $kept[$w['id']] ?? ['bonus' => 0, 'deductions' => 0, 'note' => null];
        if ((int) $w['days'] === 0 && (float) $k['bonus'] == 0.0) {
            continue;
        }
        $g = round((int) $w['days'] * (float) $w['daily_rate'] + (float) $k['bonus'], 2);
        $d = min($g, (float) $k['deductions']);
        insert('payroll_lines', ['id' => uuid(), 'farm_id' => farm_id(), 'run_id' => $run['id'], 'worker_id' => $w['id'], 'days_worked' => (int) $w['days'],
            'minutes_worked' => max(0, (int) $w['minutes']), 'tasks_verified' => (int) $w['tasks'], 'daily_rate' => $w['daily_rate'], 'bonus' => $k['bonus'], 'gross' => $g,
            'deductions' => $d, 'net' => $g - $d, 'note' => $k['note'], 'allocation' => '[]', 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        $gross += $g;
        $ded += $d;
    }
    q('UPDATE payroll_runs SET total_gross = ?, total_deductions = ?, total_net = ?, updated_at = ?, version = version + 1 WHERE id = ?', [$gross, $ded, $gross - $ded, now_utc(), $run['id']]);
}

if (is_post()) {
    $action = input('action', 20);
    handle(function () use ($action, $run, $fid) {
        if ($action === 'prepare') {
            require_can('finance.payroll.manage');
            $start = input_date('period_start') ?? fail('Give the first day of the period.');
            $end = input_date('period_end') ?? fail('Give the last day of the period.');
            ($end >= $start && (strtotime($end) - strtotime($start)) <= 62 * 86400) || fail('A period ends after it starts and is at most two months.');
            $end <= farm_today() || fail('Pay only for days that have passed.');
            $id = uuid();
            tx(function () use ($id, $fid, $start, $end) {
                $overlap = val("SELECT code FROM payroll_runs WHERE farm_id = ? AND status <> 'cancelled' AND period_start <= ? AND period_end >= ? FOR UPDATE", [$fid, $end, $start]);
                $overlap && fail("$overlap already covers part of this period.");
                insert('payroll_runs', ['id' => $id, 'farm_id' => $fid, 'code' => next_code('payroll_runs', 'PRL'), 'status' => 'draft', 'period_start' => $start, 'period_end' => $end,
                    'notes' => input('notes', 500), 'prepared_by' => $_SESSION['uid'], 'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
                payroll_compute(row('SELECT * FROM payroll_runs WHERE id = ?', [$id]));
                audit('finance.payroll.prepared', null, ['type' => 'payroll_run', 'id' => $id], null, ['period' => "$start – $end"]);
            });
            flash('success', 'Payroll prepared from attendance. Check it, then ask someone to approve it.');
            redirect('payroll.php', ['id' => $id]);
        }
        $run || fail('Choose a payroll run.');
        if ($action === 'recalculate') {
            require_can('finance.payroll.manage');
            $run['status'] === 'draft' || fail('Only a draft can change.');
            tx(fn () => payroll_compute($run));
            flash('success', 'Recalculated from attendance.');
        } elseif ($action === 'line') {
            require_can('finance.payroll.manage');
            $run['status'] === 'draft' || fail('Only a draft can change.');
            $line = row('SELECT * FROM payroll_lines WHERE id = ? AND run_id = ?', [input_id('line_id'), $run['id']]) ?? fail('Unknown line.');
            $bonus = max(0, input_num('bonus') ?? 0);
            $ded = max(0, input_num('deductions') ?? 0);
            $gross = round($line['days_worked'] * (float) $line['daily_rate'] + $bonus, 2);
            $ded <= $gross || fail('Deductions cannot be more than the pay.');
            tx(function () use ($line, $run, $bonus, $ded, $gross) {
                q('UPDATE payroll_lines SET bonus = ?, deductions = ?, gross = ?, net = ?, note = ?, updated_at = ? WHERE id = ?', [$bonus, $ded, $gross, $gross - $ded, input('note', 300), now_utc(), $line['id']]);
                q('UPDATE payroll_runs SET total_gross = (SELECT SUM(gross) FROM payroll_lines WHERE run_id = ?), total_deductions = (SELECT SUM(deductions) FROM payroll_lines WHERE run_id = ?),
                    total_net = (SELECT SUM(net) FROM payroll_lines WHERE run_id = ?), updated_at = ? WHERE id = ?', [$run['id'], $run['id'], $run['id'], now_utc(), $run['id']]);
            });
            flash('success', 'Saved.');
        } elseif ($action === 'approve') {
            require_can('finance.payroll.approve');
            $run['status'] === 'draft' || fail("{$run['code']} is " . label($run['status']) . '.');
            ($run['prepared_by'] !== $_SESSION['uid'] || is_owner()) || fail('Someone other than whoever prepared the payroll must approve it.');
            (float) $run['total_gross'] > 0 || fail('There is nothing to pay in this period.');
            tx(function () use ($run) {
                ensure_chart();
                $lines = [['account_id' => account_id('5300'), 'debit' => (float) $run['total_gross']], ['account_id' => account_id('2200'), 'credit' => (float) $run['total_net']]];
                if ((float) $run['total_deductions'] > 0) {
                    $lines[] = ['account_id' => account_id('2210'), 'credit' => (float) $run['total_deductions']];
                }
                $entry = ledger_post($run['period_end'], 'payroll_run', $run['id'], "Payroll {$run['code']} {$run['period_start']} – {$run['period_end']}", $lines);
                q("UPDATE payroll_runs SET status = 'approved', approved_by = ?, approved_at = ?, ledger_entry_id = ?, updated_at = ?, version = version + 1 WHERE id = ?",
                    [$_SESSION['uid'], now_utc(), $entry, now_utc(), $run['id']]);
                audit('finance.payroll.approved', null, ['type' => 'payroll_run', 'id' => $run['id']], ['status' => 'draft'], ['status' => 'approved', 'net' => $run['total_net']]);
            });
            flash('success', "{$run['code']} approved: wages are now owed to the workers.");
        } elseif ($action === 'cancel') {
            require_can('finance.payroll.manage');
            $run['status'] === 'draft' || fail('Only a draft can be cancelled; approved payroll is in the books.');
            q("UPDATE payroll_runs SET status = 'cancelled', updated_at = ?, version = version + 1 WHERE id = ?", [now_utc(), $run['id']]);
            audit('finance.payroll.cancelled', null, ['type' => 'payroll_run', 'id' => $run['id']], ['status' => 'draft'], ['status' => 'cancelled']);
            flash('success', "{$run['code']} cancelled.");
        } elseif ($action === 'pay') {
            require_can('finance.manage');
            $run['status'] === 'approved' || fail('Approve the payroll before paying it.');
            $due = (float) $run['total_net'] - (float) $run['paid_amount'];
            $amount = input_num('amount') ?? fail('Give the amount paid.');
            ($amount > 0 && $amount <= $due + 0.004) || fail('The amount must be above zero and at most ' . money($due) . '.');
            $cash = input_id('account_id');
            val('SELECT 1 FROM ledger_accounts WHERE id = ? AND farm_id = ? AND is_cash = 1', [$cash, $fid]) || fail('Choose where the money came from.');
            $date = input_date('paid_on') ?? farm_today();
            tx(function () use ($run, $amount, $cash, $date, $fid) {
                $pid = uuid();
                $entry = ledger_post($date, 'payment', $pid, "Wages {$run['code']}", [['account_id' => account_id('2200'), 'debit' => $amount], ['account_id' => $cash, 'credit' => $amount]]);
                insert('payments', ['id' => $pid, 'farm_id' => $fid, 'code' => next_code('payments', 'PMT', 4), 'direction' => 'out', 'status' => 'posted', 'payable_type' => 'payroll_run',
                    'payable_id' => $run['id'], 'payable_code' => $run['code'], 'party' => 'Workers', 'amount' => $amount, 'paid_on' => $date,
                    'method' => input_in('method', ['cash', 'mobile_money', 'bank', 'cheque']) ?? 'cash', 'account_id' => $cash, 'reference' => input('reference', 100),
                    'ledger_entry_id' => $entry, 'recorded_by' => $_SESSION['uid'], 'created_at' => now_utc(), 'updated_at' => now_utc()]);
                $paid = (float) $run['paid_amount'] + $amount;
                q('UPDATE payroll_runs SET paid_amount = ?, status = ?, updated_at = ?, version = version + 1 WHERE id = ?',
                    [$paid, $paid + 0.004 >= (float) $run['total_net'] ? 'paid' : 'approved', now_utc(), $run['id']]);
                audit('finance.payroll.paid', null, ['type' => 'payroll_run', 'id' => $run['id']], null, ['amount' => $amount]);
            });
            flash('success', 'Payment of ' . money($amount) . ' recorded.');
        }
    }, 'payroll.php', $run ? ['id' => $run['id']] : []);
}

if (!$run) {
    page_start('Payroll');
    $runs = rows('SELECT r.*, u.name AS preparer FROM payroll_runs r LEFT JOIN users u ON u.id = r.prepared_by WHERE r.farm_id = ? ORDER BY r.period_start DESC', [$fid]);
    echo '<div class="card">';
    table($runs, ['Run' => fn ($r) => '<a href="' . e(url('payroll.php', ['id' => $r['id']])) . '"><b>' . e($r['code']) . '</b></a>', 'Period' => fn ($r) => e(fdate($r['period_start'])) . ' – ' . e(fdate($r['period_end'])),
        'Prepared by' => fn ($r) => e($r['preparer'] ?? '—'), '#Net pay' => fn ($r) => $GLOBALS['money'] ? e(money($r['total_net'])) : '—',
        '#Paid' => fn ($r) => $GLOBALS['money'] ? e(money($r['paid_amount'])) : '—', 'Status' => fn ($r) => badge($r['status'])], 'No payroll yet.');
    echo '</div>';
    if (can('finance.payroll.manage')) {
        $last = val("SELECT MAX(period_end) FROM payroll_runs WHERE farm_id = ? AND status <> 'cancelled'", [$fid]);
        $from = $last ? date('Y-m-d', strtotime("$last +1 day")) : date('Y-m-d', strtotime(farm_today() . ' -6 days'));
        form_start('Prepare payroll');
        echo '<input type="hidden" name="action" value="prepare"><div class="fields">' . field('From', '<input type="date" name="period_start" required value="' . e($from) . '">')
            . field('To', '<input type="date" name="period_end" required value="' . e(min(farm_today(), date('Y-m-d', strtotime("$from +6 days")))) . '">') . field('Notes', '<input name="notes" maxlength="500">') . '</div>'
            . '<p class="muted">Every worker with a daily rate is paid for each day they checked in. Add bonuses and deductions after.</p>';
        form_end('Prepare');
    }
    page_end();
    exit;
}

$lines = rows('SELECT l.*, w.full_name, w.worker_code, w.phone FROM payroll_lines l JOIN workers w ON w.id = l.worker_id WHERE l.run_id = ? AND l.farm_id = ? ORDER BY w.full_name', [$run['id'], $fid]);
$names = array_column(rows('SELECT id, name FROM users WHERE id IN (?, ?)', [$run['prepared_by'] ?? '', $run['approved_by'] ?? '']), 'name', 'id');
$payments = rows("SELECT * FROM payments WHERE farm_id = ? AND payable_type = 'payroll_run' AND payable_id = ? ORDER BY paid_on", [$fid, $run['id']]);
$edit = $run['status'] === 'draft' && can('finance.payroll.manage');
$slips = input('view', 10) === 'slips';

page_start(($slips ? 'Payslips ' : 'Payroll ') . $run['code']);
echo '<p class="no-print"><a href="' . e(url('payroll.php')) . '">← Payroll</a> · ' . ($slips ? '<a href="' . e(url('payroll.php', ['id' => $run['id']])) . '">The run</a>' : '<a href="' . e(url('payroll.php', ['id' => $run['id'], 'view' => 'slips'])) . '">Payslips</a>') . ' · <button type="button" data-print>Print</button></p>';
if ($slips) {
    echo '<div class="grid">';
    foreach ($lines as $l) {
        echo '<div class="card"><h2>' . e($l['full_name']) . ' <span class="muted">' . e($l['worker_code']) . '</span></h2><p class="muted">' . e($farm['name']) . ' · ' . e(fdate($run['period_start'])) . ' – ' . e(fdate($run['period_end'])) . '</p>'
            . '<dl class="facts"><dt>Days worked</dt><dd>' . e($l['days_worked']) . ' × ' . e(money($l['daily_rate'])) . '</dd><dt>Hours</dt><dd>' . e(round($l['minutes_worked'] / 60, 1)) . '</dd>'
            . '<dt>Bonus</dt><dd>' . e(money($l['bonus'])) . '</dd><dt>Gross</dt><dd>' . e(money($l['gross'])) . '</dd><dt>Deductions</dt><dd>' . e(money($l['deductions'])) . '</dd>'
            . '<dt><b>Net pay</b></dt><dd><b>' . e(money($l['net'])) . '</b></dd>' . ($l['note'] ? '<dt>Note</dt><dd>' . e($l['note']) . '</dd>' : '') . '</dl><p class="muted">Received by: ____________________</p></div>';
    }
    echo '</div>';
    page_end();
    exit;
}
echo '<div class="card"><div class="row" style="justify-content:space-between"><h2>' . e(fdate($run['period_start'])) . ' – ' . e(fdate($run['period_end'])) . '</h2>' . badge($run['status']) . '</div>'
    . '<dl class="facts"><dt>Prepared by</dt><dd>' . e($names[$run['prepared_by']] ?? '—') . '</dd><dt>Approved by</dt><dd>' . e($names[$run['approved_by']] ?? '—') . '</dd>'
    . ($run['notes'] ? '<dt>Notes</dt><dd>' . e($run['notes']) . '</dd>' : '') . '</dl>';
$cols = ['Worker' => fn ($l) => '<b>' . e($l['full_name']) . '</b> <span class="muted">' . e($l['worker_code']) . '</span>', '#Days' => fn ($l) => e($l['days_worked']),
    '#Hours' => fn ($l) => e(round($l['minutes_worked'] / 60, 1)), '#Tasks verified' => fn ($l) => e($l['tasks_verified'])];
if ($money) {
    $cols += ['#Rate' => fn ($l) => e(money($l['daily_rate'])), '#Bonus' => fn ($l) => e(money($l['bonus'])), '#Gross' => fn ($l) => e(money($l['gross'])),
        '#Deductions' => fn ($l) => e(money($l['deductions'])), '#Net' => fn ($l) => '<b>' . e(money($l['net'])) . '</b>'];
}
if ($edit) {
    $cols[''] = fn ($l) => '<details><summary class="muted">Change</summary><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="line"><input type="hidden" name="line_id" value="' . e($l['id']) . '">'
        . '<input name="bonus" inputmode="decimal" placeholder="Bonus" value="' . e((float) $l['bonus'] ?: '') . '"><input name="deductions" inputmode="decimal" placeholder="Deductions" value="' . e((float) $l['deductions'] ?: '') . '">'
        . '<input name="note" maxlength="300" placeholder="Note" value="' . e($l['note'] ?? '') . '"><button class="small">Save</button></form></details>';
}
table($lines, $cols, 'No worker with a daily rate checked in during this period.');
if ($money) {
    echo '<p style="text-align:right">Gross ' . e(money($run['total_gross'])) . ' · Deductions ' . e(money($run['total_deductions'])) . '<br><b>Net pay ' . e(money($run['total_net'])) . '</b> · Paid ' . e(money($run['paid_amount'])) . '</p>';
}
echo '<div class="row no-print">';
if ($edit) {
    echo post_button('Recalculate from attendance', ['action' => 'recalculate'], '');
}
if ($run['status'] === 'draft' && can('finance.payroll.approve') && ($run['prepared_by'] !== $_SESSION['uid'] || is_owner())) {
    echo post_button('Approve', ['action' => 'approve'], 'primary', 'Approve this payroll? It goes into the books.');
}
if ($edit) {
    echo post_button('Cancel', ['action' => 'cancel'], 'danger', 'Cancel this draft?');
}
echo '</div></div>';
if ($payments && $money) {
    echo '<div class="card"><h2>Payments</h2>';
    table($payments, ['Payment' => fn ($p) => e($p['code']), 'Date' => fn ($p) => e(fdate($p['paid_on'])), 'Method' => fn ($p) => e(label($p['method'])) . ($p['reference'] ? ' · ' . e($p['reference']) : ''), '#Amount' => fn ($p) => e(money($p['amount']))]);
    echo '</div>';
}
if ($run['status'] === 'approved' && can('finance.manage')) {
    $cash = rows('SELECT id, name FROM ledger_accounts WHERE farm_id = ? AND is_cash = 1 ORDER BY code', [$fid]);
    form_start('Pay the workers', '', true);
    echo '<input type="hidden" name="action" value="pay"><div class="fields">' . field('Amount', '<input name="amount" required inputmode="decimal" value="' . e(round($run['total_net'] - $run['paid_amount'], 2)) . '">')
        . field('From', '<select name="account_id" required>' . options($cash, 'id', 'name', null, false) . '</select>') . field('Method', '<select name="method">' . enum_options(['cash', 'mobile_money', 'bank', 'cheque'], 'mobile_money') . '</select>')
        . field('Date', '<input type="date" name="paid_on" value="' . e(farm_today()) . '">') . field('Reference', '<input name="reference" maxlength="100">') . '</div>';
    form_end('Record payment');
}
page_end();
