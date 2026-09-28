<?php
/* One customer invoice: printable, with payments received and void by reversal. */
require __DIR__ . '/inc/bootstrap.php';

$farm = require_farm('sales.view');
$fid = $farm['id'];
$inv = farm_row('customer_invoices', input_id('id'));

if (is_post()) {
    $action = input('action', 20);
    handle(function () use ($action, $inv, $fid) {
        if ($action === 'payment') {
            require_can('finance.manage');
            $inv['status'] === 'issued' || fail('This invoice is not open.');
            $due = (float) $inv['amount'] - (float) $inv['paid_amount'];
            $amount = input_num('amount') ?? fail('Give the amount received.');
            ($amount > 0 && $amount <= $due + 0.004) || fail('The amount must be above zero and at most ' . money($due) . '.');
            $cash = input_id('account_id');
            val('SELECT 1 FROM ledger_accounts WHERE id = ? AND farm_id = ? AND is_cash = 1', [$cash, $fid]) || fail('Choose where the money went.');
            $date = input_date('paid_on') ?? farm_today();
            tx(function () use ($inv, $amount, $cash, $date, $due, $fid) {
                $pid = uuid();
                $entry = ledger_post($date, 'payment', $pid, "Payment for {$inv['code']}", [['account_id' => $cash, 'debit' => $amount], ['account_id' => account_id('1200'), 'credit' => $amount]]);
                $customer = val('SELECT name FROM customers WHERE id = ?', [$inv['customer_id']]);
                insert('payments', ['id' => $pid, 'farm_id' => $fid, 'code' => next_code('payments', 'PMT', 4), 'direction' => 'in', 'status' => 'posted', 'payable_type' => 'customer_invoice',
                    'payable_id' => $inv['id'], 'payable_code' => $inv['code'], 'party' => $customer, 'amount' => $amount, 'paid_on' => $date,
                    'method' => input_in('method', ['cash', 'mobile_money', 'bank', 'cheque']) ?? 'cash', 'account_id' => $cash, 'reference' => input('reference', 100),
                    'ledger_entry_id' => $entry, 'recorded_by' => $_SESSION['uid'], 'created_at' => now_utc(), 'updated_at' => now_utc()]);
                $paid = (float) $inv['paid_amount'] + $amount;
                q('UPDATE customer_invoices SET paid_amount = ?, status = ?, updated_at = ?, version = version + 1 WHERE id = ? AND farm_id = ?',
                    [$paid, $paid + 0.004 >= (float) $inv['amount'] ? 'paid' : 'issued', now_utc(), $inv['id'], $fid]);
            });
            flash('success', 'Payment of ' . money($amount) . ' recorded.');
        } elseif ($action === 'void') {
            require_can('sales.invoice');
            $inv['status'] === 'issued' || fail('Only an open invoice can be voided.');
            (float) $inv['paid_amount'] == 0.0 || fail('This invoice has payments: it cannot be voided.');
            $reason = input('reason', 300) ?? fail('Say why it is void.');
            tx(function () use ($inv, $reason, $fid) {
                ledger_reverse($inv['ledger_entry_id'], farm_today(), "Void {$inv['code']}: $reason");
                q("UPDATE customer_invoices SET status = 'void', voided_by = ?, voided_at = ?, void_reason = ?, updated_at = ?, version = version + 1 WHERE id = ? AND farm_id = ?",
                    [$_SESSION['uid'], now_utc(), $reason, now_utc(), $inv['id'], $fid]);
                audit('sales.invoice.voided', null, ['type' => 'customer_invoice', 'id' => $inv['id']], null, ['reason' => $reason]);
            });
            flash('success', 'Invoice voided with a reversing entry.');
        }
    }, 'invoice.php', ['id' => $inv['id']]);
}

$customer = row('SELECT * FROM customers WHERE id = ?', [$inv['customer_id']]);
$lines = rows('SELECT * FROM customer_invoice_lines WHERE invoice_id = ? AND farm_id = ? ORDER BY position', [$inv['id'], $fid]);
$payments = rows("SELECT * FROM payments WHERE farm_id = ? AND payable_type = 'customer_invoice' AND payable_id = ? ORDER BY paid_on", [$fid, $inv['id']]);
$org = row('SELECT o.name FROM organizations o WHERE o.id = ?', [$farm['organization_id']]);

page_start('Invoice ' . $inv['code']);
echo '<p class="no-print"><a href="' . e(url('sales.php')) . '">← Invoices</a> · <button type="button" data-print>Print</button></p>';
echo '<div class="card"><div class="row" style="justify-content:space-between;align-items:flex-start"><div><h2>' . e($org['name'] ?? $farm['name']) . '</h2><div class="muted">' . e($farm['name']) . ' · ' . e($farm['district'] ?? '') . '</div></div>'
    . '<div style="text-align:right"><h2>INVOICE ' . e($inv['code']) . '</h2><div>' . badge($inv['status']) . '</div><div class="muted">Date ' . e(fdate($inv['invoice_date'])) . ' · Due ' . e(fdate($inv['due_on'])) . '</div></div></div>'
    . '<h3>Bill to</h3><div><b>' . e($customer['name']) . '</b><br>' . e($customer['address'] ?? '') . '<br>' . e($customer['phone'] ?? '') . ' ' . e($customer['email'] ?? '') . '</div><br>';
table($lines, ['Description' => fn ($l) => e($l['description']), '#Quantity' => fn ($l) => e(qty($l['quantity'], $l['unit'])), '#Unit price' => fn ($l) => e(money($l['unit_price'])), '#Amount' => fn ($l) => e(money($l['amount']))]);
echo '<p style="text-align:right;font-size:1.1rem"><b>Total ' . e(money($inv['amount'])) . '</b><br>Paid ' . e(money($inv['paid_amount'])) . '<br><b>Still due ' . e(money($inv['status'] === 'void' ? 0 : $inv['amount'] - $inv['paid_amount'])) . '</b></p>'
    . ($inv['notes'] ? '<p>' . e($inv['notes']) . '</p>' : '') . ($inv['void_reason'] ? '<p class="flash error">Void: ' . e($inv['void_reason']) . '</p>' : '') . '</div>';

echo '<div class="card"><h2>Payments</h2>';
table($payments, ['Payment' => fn ($p) => e($p['code']), 'Date' => fn ($p) => e(fdate($p['paid_on'])), 'Method' => fn ($p) => e(label($p['method'])) . ($p['reference'] ? ' · ' . e($p['reference']) : ''), '#Amount' => fn ($p) => e(money($p['amount']))], 'No payments yet.');
echo '</div>';
if ($inv['status'] === 'issued') {
    echo '<div class="grid">';
    if (can('finance.manage')) {
        $cash = rows('SELECT id, name FROM ledger_accounts WHERE farm_id = ? AND is_cash = 1 ORDER BY code', [$fid]);
        form_start('Record a payment received', '', true);
        echo '<input type="hidden" name="action" value="payment"><div class="fields">' . field('Amount', '<input name="amount" required inputmode="decimal" value="' . e(round($inv['amount'] - $inv['paid_amount'], 2)) . '">')
            . field('Into', '<select name="account_id" required>' . options($cash, 'id', 'name', null, false) . '</select>') . field('Method', '<select name="method">' . enum_options(['cash', 'mobile_money', 'bank', 'cheque'], 'mobile_money') . '</select>')
            . field('Date', '<input type="date" name="paid_on" value="' . e(farm_today()) . '">') . field('Reference', '<input name="reference" maxlength="100">') . '</div>';
        form_end('Record payment');
    }
    if (can('sales.invoice') && (float) $inv['paid_amount'] == 0.0) {
        form_start('Void this invoice');
        echo '<input type="hidden" name="action" value="void">' . field('Reason', '<input name="reason" required maxlength="300">');
        form_end('Void invoice');
    }
    echo '</div>';
}
page_end();
