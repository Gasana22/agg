<?php
/*
 * Budgets: planned amounts per income or expense account for a period, for
 * the whole farm or one cost centre (a crop cycle or animal group). Actual
 * figures come straight from the ledger, so they need no upkeep.
 */
require __DIR__ . '/inc/bootstrap.php';

$farm = require_farm('finance.view');
$fid = $farm['id'];
$budget = input_id('id') ? farm_row('budgets', input_id('id')) : null;

if (is_post()) {
    $action = input('action', 20);
    handle(function () use ($action, $budget, $fid) {
        require_can('finance.budgets.manage');
        if ($action === 'create') {
            $name = input('name', 150) ?? fail('Name the budget.');
            $start = input_date('period_start') ?? fail('Give the start of the period.');
            $end = input_date('period_end') ?? fail('Give the end of the period.');
            $end >= $start || fail('The period ends after it starts.');
            [$type, $sid] = array_pad(explode(':', (string) input('scope', 80), 2), 2, null);
            $label = null;
            if ($type === 'crop_cycle' || $type === 'animal_group') {
                $r = $type === 'crop_cycle'
                    ? row('SELECT c.id, CONCAT(c.code, \' \', cr.name) AS label FROM crop_cycles c JOIN crops cr ON cr.id = c.crop_id WHERE c.id = ? AND c.farm_id = ?', [uuid_or_null($sid), $fid])
                    : row('SELECT id, CONCAT(code, \' \', name) AS label FROM animal_groups WHERE id = ? AND farm_id = ?', [uuid_or_null($sid), $fid]);
                $r || fail('Choose a crop cycle or animal group of this farm.');
                $label = $r['label'];
            } else {
                $type = $sid = null;
            }
            $id = uuid();
            insert('budgets', ['id' => $id, 'farm_id' => $fid, 'code' => next_code('budgets', 'BUD'), 'name' => $name, 'period_start' => $start, 'period_end' => $end,
                'scope_type' => $type, 'scope_id' => $sid, 'scope_label' => $label, 'status' => 'active', 'notes' => input('notes', 500), 'created_by' => $_SESSION['uid'],
                'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
            audit('finance.budget.created', null, ['type' => 'budget', 'id' => $id], null, ['name' => $name]);
            flash('success', 'Budget created. Add the amounts you plan.');
            redirect('budgets.php', ['id' => $id]);
        }
        $budget || fail('Choose a budget.');
        if ($action === 'lines') {
            ensure_chart();
            tx(function () use ($budget, $fid) {
                foreach ((array) ($_POST['amount'] ?? []) as $accountId => $v) {
                    if (!uuid_or_null($accountId) || !val("SELECT 1 FROM ledger_accounts WHERE id = ? AND farm_id = ? AND type IN ('income','expense')", [$accountId, $fid])) {
                        continue;
                    }
                    $amount = num($v);
                    $existing = val('SELECT id FROM budget_lines WHERE budget_id = ? AND account_id = ?', [$budget['id'], $accountId]);
                    if ($amount === null || $amount <= 0) {
                        $existing && q('DELETE FROM budget_lines WHERE id = ?', [$existing]);
                    } elseif ($existing) {
                        q('UPDATE budget_lines SET amount = ?, updated_at = ? WHERE id = ?', [$amount, now_utc(), $existing]);
                    } else {
                        insert('budget_lines', ['id' => uuid(), 'farm_id' => $fid, 'budget_id' => $budget['id'], 'account_id' => $accountId, 'amount' => $amount, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
                    }
                }
                audit('finance.budget.updated', null, ['type' => 'budget', 'id' => $budget['id']]);
            });
            flash('success', 'Budget saved.');
        } elseif ($action === 'close') {
            q("UPDATE budgets SET status = IF(status = 'active', 'archived', 'active'), updated_at = ? WHERE id = ? AND farm_id = ?", [now_utc(), $budget['id'], $fid]);
            flash('success', 'Saved.');
        }
    }, 'budgets.php', $budget ? ['id' => $budget['id']] : []);
}

/** Budget against actual for each line (expenses: debits − credits; income: credits − debits). */
function budget_vs_actual(array $b): array
{
    $params = [$b['period_start'], $b['period_end'], $b['farm_id']];
    $scope = '';
    if ($b['scope_type']) {
        $scope = ' AND l.cost_center_type = ? AND l.cost_center_id = ?';
        array_push($params, $b['scope_type'], $b['scope_id']);
    }
    $actual = [];
    foreach (rows("SELECT l.account_id, SUM(l.debit) AS d, SUM(l.credit) AS c FROM ledger_lines l JOIN ledger_entries e ON e.id = l.entry_id
        WHERE e.posted_on BETWEEN ? AND ? AND l.farm_id = ?$scope GROUP BY l.account_id", $params) as $r) {
        $actual[$r['account_id']] = $r;
    }
    $out = [];
    foreach (rows('SELECT bl.*, a.code, a.name, a.type FROM budget_lines bl JOIN ledger_accounts a ON a.id = bl.account_id WHERE bl.budget_id = ? ORDER BY a.code', [$b['id']]) as $l) {
        $a = $actual[$l['account_id']] ?? ['d' => 0, 'c' => 0];
        $act = $l['type'] === 'income' ? (float) $a['c'] - (float) $a['d'] : (float) $a['d'] - (float) $a['c'];
        $out[] = $l + ['actual' => $act, 'used_pct' => (float) $l['amount'] > 0 ? round($act * 100 / (float) $l['amount'], 1) : null];
    }
    return $out;
}

if (!$budget) {
    page_start('Budgets');
    $list = rows('SELECT b.*, (SELECT SUM(amount) FROM budget_lines bl JOIN ledger_accounts a ON a.id = bl.account_id WHERE bl.budget_id = b.id AND a.type = \'expense\') AS costs
        FROM budgets b WHERE b.farm_id = ? ORDER BY b.status = \'active\' DESC, b.period_start DESC', [$fid]);
    echo '<div class="card">';
    table($list, ['Budget' => fn ($b) => '<a href="' . e(url('budgets.php', ['id' => $b['id']])) . '"><b>' . e($b['name']) . '</b></a> <span class="muted">' . e($b['code']) . '</span>',
        'For' => fn ($b) => e($b['scope_label'] ?? 'Whole farm'), 'Period' => fn ($b) => e(fdate($b['period_start'])) . ' – ' . e(fdate($b['period_end'])),
        '#Planned costs' => fn ($b) => e(money($b['costs'] ?? 0)), 'Status' => fn ($b) => badge($b['status'])], 'No budgets yet.');
    echo '</div>';
    if (can('finance.budgets.manage')) {
        $scopes = [['v' => '', 't' => 'Whole farm']];
        foreach (rows("SELECT c.id, c.code, cr.name FROM crop_cycles c JOIN crops cr ON cr.id = c.crop_id WHERE c.farm_id = ? AND c.stage <> 'closed' ORDER BY c.code", [$fid]) as $c) {
            $scopes[] = ['v' => 'crop_cycle:' . $c['id'], 't' => "Crop cycle {$c['code']} {$c['name']}"];
        }
        foreach (rows('SELECT id, code, name FROM animal_groups WHERE farm_id = ? AND is_active = 1 ORDER BY code', [$fid]) as $g) {
            $scopes[] = ['v' => 'animal_group:' . $g['id'], 't' => "Animal group {$g['code']} {$g['name']}"];
        }
        form_start('New budget');
        echo '<input type="hidden" name="action" value="create"><div class="fields">' . field('Name', '<input name="name" required maxlength="150" placeholder="e.g. Season A maize">')
            . field('For', '<select name="scope">' . options($scopes, 'v', 't', null, false) . '</select>')
            . field('From', '<input type="date" name="period_start" required>') . field('To', '<input type="date" name="period_end" required>') . field('Notes', '<input name="notes" maxlength="500">') . '</div>';
        form_end('Create budget');
    }
    page_end();
    exit;
}

$lines = budget_vs_actual($budget);
$planned = ['income' => 0.0, 'expense' => 0.0];
$actual = ['income' => 0.0, 'expense' => 0.0];
foreach ($lines as $l) {
    $planned[$l['type']] += (float) $l['amount'];
    $actual[$l['type']] += $l['actual'];
}
page_start($budget['name']);
echo '<p class="no-print"><a href="' . e(url('budgets.php')) . '">← Budgets</a> · <button type="button" data-print>Print</button></p>';
echo '<p class="muted">' . e($budget['scope_label'] ?? 'Whole farm') . ' · ' . e(fdate($budget['period_start'])) . ' – ' . e(fdate($budget['period_end'])) . ' ' . badge($budget['status']) . '</p>';
echo '<div class="kpis">' . kpi('Planned costs', e(money($planned['expense']))) . kpi('Actual costs', e(money($actual['expense'])), $planned['expense'] > 0 ? round($actual['expense'] * 100 / $planned['expense']) . '% used' : null)
    . kpi('Planned income', e(money($planned['income']))) . kpi('Actual income', e(money($actual['income']))) . '</div><div class="card">';
table($lines, ['Account' => fn ($l) => e($l['code']) . ' ' . e($l['name']), 'Kind' => fn ($l) => e(label($l['type'])), '#Planned' => fn ($l) => e(money($l['amount'])),
    '#Actual' => fn ($l) => e(money($l['actual'])), '#Difference' => fn ($l) => e(money($l['type'] === 'expense' ? $l['amount'] - $l['actual'] : $l['actual'] - $l['amount'])),
    'Used' => fn ($l) => $l['used_pct'] === null ? '—' : '<span class="badge ' . ($l['type'] === 'expense' && $l['used_pct'] > 100 ? 'bad' : ($l['used_pct'] > 85 ? 'warn' : 'ok')) . '">' . e($l['used_pct']) . '%</span>'],
    'No amounts planned yet.');
echo '<p class="muted">Actual figures come from the books for the period' . ($budget['scope_type'] ? ', counting only postings charged to ' . e($budget['scope_label']) : '') . '.</p></div>';
if (can('finance.budgets.manage')) {
    ensure_chart();
    $have = array_column($lines, 'amount', 'account_id');
    $accounts = rows("SELECT id, code, name, type FROM ledger_accounts WHERE farm_id = ? AND type IN ('income','expense') AND is_active = 1 ORDER BY code", [$fid]);
    form_start('Plan the amounts');
    echo '<input type="hidden" name="action" value="lines"><div class="fields">';
    foreach ($accounts as $a) {
        echo field($a['code'] . ' ' . $a['name'], '<input name="amount[' . e($a['id']) . ']" inputmode="decimal" value="' . e(isset($have[$a['id']]) ? (float) $have[$a['id']] : '') . '">');
    }
    echo '</div>';
    form_end('Save budget');
    echo '<p>' . post_button($budget['status'] === 'active' ? 'Archive this budget' : 'Reopen', ['action' => 'close'], 'small') . '</p>';
}
page_end();
