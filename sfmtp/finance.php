<?php
/* Finance: expenses (with approval above the farm's threshold), income, the journal, profit and loss, and the trial balance. */
require __DIR__ . '/inc/bootstrap.php';

$farm = require_farm('finance.view');
$fid = $farm['id'];
ensure_chart();
$tab = input_in('tab', ['expenses', 'income', 'journal', 'pl', 'accounts']) ?? 'expenses';

function cash_accounts(): array
{
    return rows('SELECT id, code, name FROM ledger_accounts WHERE farm_id = ? AND is_cash = 1 AND is_active = 1 ORDER BY code', [farm_id()]);
}

function accounts_of(string $type): array
{
    return rows('SELECT id, code, name FROM ledger_accounts WHERE farm_id = ? AND type = ? AND is_active = 1 ORDER BY code', [farm_id(), $type]);
}

/** Record a payment of an expense out of a cash account. */
function pay_expense(array $exp, string $cashId, string $date, string $method, ?string $ref): void
{
    tx(function () use ($exp, $cashId, $date, $method, $ref) {
        $due = (float) $exp['amount'] - (float) $exp['paid_amount'];
        $pid = uuid();
        $entry = ledger_post($date, 'payment', $pid, "Payment of {$exp['code']}: {$exp['description']}", [
            ['account_id' => account_id('2000'), 'debit' => $due], ['account_id' => $cashId, 'credit' => $due]]);
        insert('payments', ['id' => $pid, 'farm_id' => farm_id(), 'code' => next_code('payments', 'PMT', 4), 'direction' => 'out', 'status' => 'posted', 'payable_type' => 'expense',
            'payable_id' => $exp['id'], 'payable_code' => $exp['code'], 'party' => $exp['payee'], 'amount' => $due, 'paid_on' => $date, 'method' => $method, 'account_id' => $cashId,
            'reference' => $ref, 'ledger_entry_id' => $entry, 'recorded_by' => $_SESSION['uid'], 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        q("UPDATE expenses SET paid_amount = amount, status = 'paid', updated_at = ?, version = version + 1 WHERE id = ? AND farm_id = ?", [now_utc(), $exp['id'], farm_id()]);
    });
}

if (is_post()) {
    $action = input('action', 20);
    handle(function () use ($action, $fid) {
        $methods = ['cash', 'mobile_money', 'bank', 'cheque'];
        if ($action === 'expense') {
            require_can('finance.expenses.request');
            $acc = input_id('account_id');
            val("SELECT 1 FROM ledger_accounts WHERE id = ? AND farm_id = ? AND type = 'expense'", [$acc, $fid]) || fail('Choose the kind of cost.');
            $amount = input_num('amount') ?? fail('Give the amount.');
            $amount > 0 || fail('The amount must be above zero.');
            $date = input_date('spent_on') ?? farm_today();
            $desc = input('description', 300) ?? fail('Describe the cost.');
            $cash = input_id('paid_from');
            if ($cash && !val('SELECT 1 FROM ledger_accounts WHERE id = ? AND farm_id = ? AND is_cash = 1', [$cash, $fid])) {
                fail('Unknown cash account.');
            }
            $threshold = farm_settings()['approval_thresholds']['expense'] ?? null;
            $needsApproval = $threshold !== null && $amount > (float) $threshold && !can('finance.approve');
            $id = uuid();
            tx(function () use ($id, $fid, $acc, $amount, $date, $desc, $cash, $needsApproval) {
                $exp = ['id' => $id, 'farm_id' => $fid, 'code' => next_code('expenses', 'EXP'), 'status' => $needsApproval ? 'requested' : 'approved', 'account_id' => $acc,
                    'amount' => $amount, 'paid_amount' => 0, 'spent_on' => $date, 'payee' => input('payee', 150), 'description' => $desc, 'requested_by' => $_SESSION['uid'],
                    'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()];
                if (!$needsApproval) {
                    $exp['decided_by'] = $_SESSION['uid'];
                    $exp['decided_at'] = now_utc();
                    $exp['ledger_entry_id'] = ledger_post($date, 'expense', $id, "{$exp['code']}: $desc", [
                        ['account_id' => $acc, 'debit' => $amount], ['account_id' => $cash ?: account_id('2000'), 'credit' => $amount]]);
                    if ($cash) {
                        $exp['status'] = 'paid';
                        $exp['paid_amount'] = $amount;
                        $exp['paid_from_account_id'] = $cash;
                    }
                }
                insert('expenses', $exp);
                audit('finance.expense.created', null, ['type' => 'expense', 'id' => $id], null, ['amount' => $amount, 'status' => $exp['status']]);
            });
            flash($needsApproval ? 'warn' : 'success', $needsApproval ? 'Above the approval limit: sent to the owner for approval.' : 'Expense recorded.');
        } elseif ($action === 'decide') {
            require_can('finance.approve');
            $exp = farm_row('expenses', input_id('expense_id'));
            $exp['status'] === 'requested' || fail('Already decided.');
            $exp['requested_by'] === $_SESSION['uid'] && fail('Someone else must approve your own expense.');
            $decision = input_in('decision', ['approved', 'rejected']) ?? fail('Approve or reject.');
            tx(function () use ($exp, $decision, $fid) {
                $entry = $decision === 'approved' ? ledger_post($exp['spent_on'], 'expense', $exp['id'], "{$exp['code']}: {$exp['description']}", [
                    ['account_id' => $exp['account_id'], 'debit' => $exp['amount']], ['account_id' => account_id('2000'), 'credit' => $exp['amount']]]) : null;
                q('UPDATE expenses SET status = ?, decided_by = ?, decided_at = ?, decision_note = ?, ledger_entry_id = ?, updated_at = ?, version = version + 1 WHERE id = ? AND farm_id = ?',
                    [$decision, $_SESSION['uid'], now_utc(), input('note', 500), $entry, now_utc(), $exp['id'], $fid]);
                audit("finance.expense.$decision", null, ['type' => 'expense', 'id' => $exp['id']]);
            });
            flash('success', 'Expense ' . $decision . '.');
        } elseif ($action === 'pay') {
            require_can('finance.manage');
            $exp = farm_row('expenses', input_id('expense_id'));
            $exp['status'] === 'approved' || fail('Only approved, unpaid expenses can be paid.');
            $cash = input_id('account_id');
            val('SELECT 1 FROM ledger_accounts WHERE id = ? AND farm_id = ? AND is_cash = 1', [$cash, $fid]) || fail('Choose where the money comes from.');
            pay_expense($exp, $cash, input_date('paid_on') ?? farm_today(), input_in('method', $methods) ?? 'cash', input('reference', 100));
            flash('success', "{$exp['code']} paid.");
        } elseif ($action === 'income') {
            require_can('finance.manage');
            $acc = input_id('account_id');
            val("SELECT 1 FROM ledger_accounts WHERE id = ? AND farm_id = ? AND type = 'income'", [$acc, $fid]) || fail('Choose the kind of income.');
            $cash = input_id('received_into');
            val('SELECT 1 FROM ledger_accounts WHERE id = ? AND farm_id = ? AND is_cash = 1', [$cash, $fid]) || fail('Choose where the money went.');
            $amount = input_num('amount') ?? fail('Give the amount.');
            $amount > 0 || fail('The amount must be above zero.');
            $date = input_date('received_on') ?? farm_today();
            $desc = input('description', 300) ?? fail('Describe the income.');
            $id = uuid();
            tx(function () use ($id, $fid, $acc, $cash, $amount, $date, $desc) {
                $code = next_code('income_records', 'INC');
                $entry = ledger_post($date, 'income', $id, "$code: $desc", [['account_id' => $cash, 'debit' => $amount], ['account_id' => $acc, 'credit' => $amount]]);
                insert('income_records', ['id' => $id, 'farm_id' => $fid, 'code' => $code, 'status' => 'recorded', 'account_id' => $acc, 'received_into_account_id' => $cash, 'amount' => $amount,
                    'received_on' => $date, 'payer' => input('payer', 150), 'description' => $desc, 'ledger_entry_id' => $entry, 'recorded_by' => $_SESSION['uid'],
                    'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
            });
            flash('success', 'Income recorded.');
        } elseif ($action === 'void_income') {
            require_can('finance.manage');
            $inc = farm_row('income_records', input_id('income_id'));
            $inc['status'] === 'recorded' || fail('Already void.');
            $reason = input('reason', 300) ?? fail('Say why it is void.');
            tx(function () use ($inc, $reason, $fid) {
                ledger_reverse($inc['ledger_entry_id'], farm_today(), "Void {$inc['code']}: $reason");
                q("UPDATE income_records SET status = 'void', voided_by = ?, voided_at = ?, void_reason = ?, updated_at = ?, version = version + 1 WHERE id = ? AND farm_id = ?",
                    [$_SESSION['uid'], now_utc(), $reason, now_utc(), $inc['id'], $fid]);
                audit('finance.income.voided', null, ['type' => 'income', 'id' => $inc['id']], null, ['reason' => $reason]);
            });
            flash('success', "{$inc['code']} voided with a reversing entry.");
        }
    }, 'finance.php', ['tab' => in_array($action, ['income', 'void_income'], true) ? 'income' : 'expenses']);
}

page_start('Finance');
tabs(['expenses' => 'Expenses', 'income' => 'Income', 'journal' => 'Journal', 'pl' => 'Profit & loss', 'accounts' => 'Trial balance'], $tab);
$cash = cash_accounts();

if ($tab === 'expenses') {
    $list = rows('SELECT x.*, a.code AS acc_code, a.name AS acc_name, u.name AS requester FROM expenses x JOIN ledger_accounts a ON a.id = x.account_id LEFT JOIN users u ON u.id = x.requested_by
        WHERE x.farm_id = ? ORDER BY x.status = \'requested\' DESC, x.spent_on DESC LIMIT 200', [$fid]);
    echo '<div class="card">';
    table($list, [
        'Expense' => fn ($x) => '<b>' . e($x['code']) . '</b> ' . e($x['description']) . '<div class="muted">' . e($x['payee'] ?? '') . '</div>',
        'Date' => fn ($x) => e(fdate($x['spent_on'])),
        'Kind' => fn ($x) => e($x['acc_code'] . ' ' . $x['acc_name']),
        '#Amount' => fn ($x) => e(money($x['amount'])),
        'Status' => fn ($x) => badge($x['status']),
        'Action' => function ($x) use ($cash) {
            if ($x['status'] === 'requested' && can('finance.approve') && $x['requested_by'] !== $_SESSION['uid']) {
                return post_button('Approve', ['action' => 'decide', 'expense_id' => $x['id'], 'decision' => 'approved'], 'small primary') . ' '
                    . post_button('Reject', ['action' => 'decide', 'expense_id' => $x['id'], 'decision' => 'rejected'], 'small danger', 'Reject this expense?');
            }
            if ($x['status'] === 'approved' && can('finance.manage')) {
                return '<form method="post" class="row">' . csrf_field() . '<input type="hidden" name="action" value="pay"><input type="hidden" name="expense_id" value="' . e($x['id']) . '">'
                    . '<select name="account_id" style="max-width:150px">' . options($cash, 'id', 'name', null, false) . '</select><select name="method" style="max-width:130px">'
                    . enum_options(['cash', 'mobile_money', 'bank', 'cheque']) . '</select><button class="small">Pay</button></form>';
            }
            return $x['status'] === 'requested' ? '<span class="muted">Waiting for approval</span>' : '';
        },
    ], 'No expenses.');
    echo '</div>';
    if (can('finance.expenses.request')) {
        $threshold = farm_settings()['approval_thresholds']['expense'] ?? null;
        form_start('Record an expense');
        echo '<input type="hidden" name="action" value="expense"><div class="fields">'
            . field('Kind of cost', '<select name="account_id" required>' . options(accounts_of('expense'), 'id', fn ($a) => $a['code'] . ' ' . $a['name']) . '</select>')
            . field('Amount', '<input name="amount" required inputmode="decimal">', $threshold !== null ? 'Above ' . money($threshold) . ' needs the owner\'s approval.' : null)
            . field('Date', '<input type="date" name="spent_on" value="' . e(farm_today()) . '">') . field('Paid to', '<input name="payee" maxlength="150">')
            . field('Description', '<input name="description" required maxlength="300">')
            . field('Paid already from', '<select name="paid_from">' . options($cash, 'id', 'name') . '</select>', 'Leave empty if it is still to be paid.') . '</div>';
        form_end('Save expense');
    }
} elseif ($tab === 'income') {
    $list = rows('SELECT i.*, a.name AS acc_name, c.name AS cash_name FROM income_records i JOIN ledger_accounts a ON a.id = i.account_id JOIN ledger_accounts c ON c.id = i.received_into_account_id
        WHERE i.farm_id = ? ORDER BY i.received_on DESC LIMIT 200', [$fid]);
    echo '<div class="card">';
    table($list, ['Income' => fn ($i) => '<b>' . e($i['code']) . '</b> ' . e($i['description']) . '<div class="muted">' . e($i['payer'] ?? '') . '</div>', 'Date' => fn ($i) => e(fdate($i['received_on'])),
        'Kind' => fn ($i) => e($i['acc_name']), 'Into' => fn ($i) => e($i['cash_name']), '#Amount' => fn ($i) => e(money($i['amount'])),
        'Status' => fn ($i) => badge($i['status'] === 'void' ? 'void' : 'recorded') . ($i['status'] === 'recorded' && can('finance.manage')
            ? '<form method="post" class="row" data-confirm="Void this income? A reversing entry is posted.">' . csrf_field() . '<input type="hidden" name="action" value="void_income"><input type="hidden" name="income_id" value="' . e($i['id']) . '"><input name="reason" placeholder="Reason" style="max-width:140px"><button class="small danger">Void</button></form>' : '')], 'No income recorded.');
    echo '</div>';
    if (can('finance.manage')) {
        form_start('Record income');
        echo '<input type="hidden" name="action" value="income"><div class="fields">'
            . field('Kind', '<select name="account_id" required>' . options(accounts_of('income'), 'id', fn ($a) => $a['code'] . ' ' . $a['name']) . '</select>')
            . field('Received into', '<select name="received_into" required>' . options($cash, 'id', 'name') . '</select>')
            . field('Amount', '<input name="amount" required inputmode="decimal">') . field('Date', '<input type="date" name="received_on" value="' . e(farm_today()) . '">')
            . field('From', '<input name="payer" maxlength="150">') . field('Description', '<input name="description" required maxlength="300">') . '</div>';
        form_end('Save income');
    }
} elseif ($tab === 'journal') {
    $entries = rows('SELECT e.*, u.name AS by_name FROM ledger_entries e LEFT JOIN users u ON u.id = e.posted_by WHERE e.farm_id = ? ORDER BY e.number DESC LIMIT 100', [$fid]);
    $lines = [];
    if ($entries) {
        $ids = array_column($entries, 'id');
        foreach (rows('SELECT l.*, a.code, a.name FROM ledger_lines l JOIN ledger_accounts a ON a.id = l.account_id WHERE l.entry_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $l) {
            $lines[$l['entry_id']][] = $l;
        }
    }
    echo '<div class="card">';
    table($entries, [
        'Entry' => fn ($x) => '<b>' . e($x['number']) . '</b><div class="muted">' . e(fdate($x['posted_on'])) . '</div>',
        'Memo' => fn ($x) => e($x['memo']) . '<div class="muted">' . e(label($x['source_type'])) . ' · ' . e($x['by_name'] ?? '') . '</div>',
        'Lines' => fn ($x) => implode('<br>', array_map(fn ($l) => e($l['code'] . ' ' . $l['name']) . ': ' . ($l['debit'] > 0 ? 'Dr ' . e(money($l['debit'])) : 'Cr ' . e(money($l['credit']))), $lines[$x['id']] ?? [])),
    ], 'No entries yet.');
    echo '</div>';
} elseif ($tab === 'pl') {
    $from = input_date('from') ?? substr(farm_today(), 0, 5) . '01-01';
    $to = input_date('to') ?? farm_today();
    $bal = account_balances($to, $from);
    $income = array_filter($bal, fn ($a) => $a['type'] === 'income' && ($a['credit'] - $a['debit']) != 0);
    $expense = array_filter($bal, fn ($a) => $a['type'] === 'expense' && ($a['debit'] - $a['credit']) != 0);
    $ti = array_sum(array_map(fn ($a) => $a['credit'] - $a['debit'], $income));
    $te = array_sum(array_map(fn ($a) => $a['debit'] - $a['credit'], $expense));
    echo '<form class="row no-print" method="get"><input type="hidden" name="tab" value="pl"><input type="date" name="from" value="' . e($from) . '" style="max-width:170px"> to <input type="date" name="to" value="' . e($to) . '" style="max-width:170px"><button>Show</button><button type="button" data-print>Print</button></form><br>';
    echo '<div class="kpis">' . kpi('Income', e(money($ti))) . kpi('Costs', e(money($te))) . kpi($ti - $te >= 0 ? 'Profit' : 'Loss', e(money($ti - $te))) . '</div><div class="grid"><div class="card"><h2>Income</h2>';
    table(array_values($income), ['Account' => fn ($a) => e($a['code'] . ' ' . $a['name']), '#Amount' => fn ($a) => e(money($a['credit'] - $a['debit']))], 'No income in this period.');
    echo '</div><div class="card"><h2>Costs</h2>';
    table(array_values($expense), ['Account' => fn ($a) => e($a['code'] . ' ' . $a['name']), '#Amount' => fn ($a) => e(money($a['debit'] - $a['credit']))], 'No costs in this period.');
    echo '</div></div>';
} else {
    $bal = account_balances();
    $td = array_sum(array_column($bal, 'debit'));
    $tc = array_sum(array_column($bal, 'credit'));
    echo '<div class="card"><p>' . (abs($td - $tc) < 0.01 ? badge('active') . ' The books balance: debits equal credits (' . e(money($td)) . ').' : badge('failed') . ' Out of balance by ' . e(money($td - $tc))) . '</p>';
    table($bal, ['Account' => fn ($a) => e($a['code'] . ' ' . $a['name']), 'Type' => fn ($a) => e(label($a['type'])),
        '#Debit' => fn ($a) => $a['debit'] - $a['credit'] > 0 ? e(money($a['debit'] - $a['credit'])) : '', '#Credit' => fn ($a) => $a['credit'] - $a['debit'] > 0 ? e(money($a['credit'] - $a['debit'])) : '']);
    echo '</div>';
}
page_end();
