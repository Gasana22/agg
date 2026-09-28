<?php
/* One purchase order: approve and send, what the supplier said and sent, receiving, invoices and payments. */
require __DIR__ . '/inc/bootstrap.php';

$farm = require_farm('suppliers.view');
$fid = $farm['id'];
$po = farm_row('purchase_orders', input_id('id'));
$money = can('finance.view') || can('procurement.orders.manage') || can('inventory.values.view');

if (is_post()) {
    $action = input('action', 20);
    handle(function () use ($action, $po, $fid) {
        $settings = farm_settings();
        if ($action === 'approve') {
            require_can('procurement.orders.approve');
            $po['status'] === 'draft' || fail("{$po['code']} is " . label($po['status']) . '.');
            ($po['created_by'] !== $_SESSION['uid'] || is_owner()) || fail('Someone other than the buyer must approve this order.');
            $limit = $settings['approval_thresholds']['purchase_order'] ?? null;
            if ($limit && (float) $po['total_amount'] > (float) $limit && !is_owner()) {
                fail('Orders above ' . money($limit) . ' need the owner.');
            }
            q("UPDATE purchase_orders SET status = 'approved', approved_by = ?, approved_at = ?, updated_at = ?, version = version + 1 WHERE id = ? AND farm_id = ?",
                [$_SESSION['uid'], now_utc(), now_utc(), $po['id'], $fid]);
            audit('procurement.order.approved', null, ['type' => 'purchase_order', 'id' => $po['id']], ['status' => 'draft'], ['status' => 'approved', 'total' => $po['total_amount']]);
            flash('success', "{$po['code']} approved.");
        } elseif ($action === 'send') {
            require_can('procurement.orders.manage');
            $po['status'] === 'approved' || fail('Approve the order first.');
            q("UPDATE purchase_orders SET status = 'sent', sent_at = ?, updated_at = ?, version = version + 1 WHERE id = ? AND farm_id = ?", [now_utc(), now_utc(), $po['id'], $fid]);
            audit('procurement.order.sent', null, ['type' => 'purchase_order', 'id' => $po['id']], ['status' => 'approved'], ['status' => 'sent']);
            $linked = val("SELECT 1 FROM party_links WHERE farm_id = ? AND kind = 'supplier' AND record_id = ? AND status = 'active'", [$fid, $po['supplier_id']]);
            flash('success', $linked ? "{$po['code']} sent: the supplier sees it in their portal." : "{$po['code']} marked as sent. Print it or share it with the supplier.");
        } elseif ($action === 'cancel') {
            require_can('procurement.orders.manage');
            in_array($po['status'], ['draft', 'approved', 'sent'], true) || fail('Orders with goods received are closed, not cancelled.');
            $reason = input('reason', 500) ?? fail('Say why the order is cancelled.');
            q("UPDATE purchase_orders SET status = 'cancelled', cancel_reason = ?, updated_at = ?, version = version + 1 WHERE id = ? AND farm_id = ?", [$reason, now_utc(), $po['id'], $fid]);
            audit('procurement.order.cancelled', null, ['type' => 'purchase_order', 'id' => $po['id']], ['status' => $po['status']], ['status' => 'cancelled', 'reason' => $reason]);
            flash('success', "{$po['code']} cancelled.");
        } elseif ($action === 'close') {
            require_can('procurement.orders.manage');
            in_array($po['status'], ['partially_received', 'received'], true) || fail('Only an order with goods received can be closed.');
            q("UPDATE purchase_orders SET status = 'closed', updated_at = ?, version = version + 1 WHERE id = ? AND farm_id = ?", [now_utc(), $po['id'], $fid]);
            audit('procurement.order.closed', null, ['type' => 'purchase_order', 'id' => $po['id']], ['status' => $po['status']], ['status' => 'closed']);
            flash('success', "{$po['code']} closed: nothing more is expected.");
        } elseif ($action === 'receive') {
            require_can('procurement.deliveries.receive');
            $qty = $lots = [];
            foreach ((array) ($_POST['lines'] ?? []) as $lineId => $l) {
                if (uuid_or_null($lineId) && ($q = num($l['quantity'] ?? null))) {
                    $qty[$lineId] = $q;
                    $lots[$lineId] = mb_substr(trim((string) ($l['lot_number'] ?? '')), 0, 60) ?: null;
                }
            }
            po_receive($po, input_id('location_id') ?? fail('Choose the store.'), input_date('received_on') ?? farm_today(), $qty, $lots, input_id('dispatch_id'), input('reference', 60), input('note', 500));
            flash('success', 'Goods received into stock.');
        } elseif ($action === 'invoice') {
            require_can('finance.manage');
            $lines = [];
            foreach ((array) ($_POST['lines'] ?? []) as $lineId => $l) {
                if (uuid_or_null($lineId) && ($q = num($l['quantity'] ?? null))) {
                    $lines[$lineId] = ['quantity' => $q, 'unit_price' => num($l['unit_price'] ?? null)];
                }
            }
            po_record_invoice($po, input('invoice_number', 60) ?? fail('Give the supplier\'s invoice number.'), input_date('invoice_date') ?? farm_today(), input_date('due_on'), input('notes', 500), $lines);
            flash('success', 'Invoice recorded: the farm now owes the supplier.');
        } elseif ($action === 'record_sub') {
            require_can('finance.manage');
            $sub = row("SELECT * FROM supplier_invoice_submissions WHERE id = ? AND order_id = ? AND farm_id = ? AND status = 'submitted'", [input_id('submission_id'), $po['id'], $fid])
                ?? fail('This invoice has already been dealt with.');
            tx(function () use ($sub, $po) {
                $lines = [];
                foreach (json_decode($sub['lines'], true) as $l) {
                    $lines[$l['order_line_id']] = ['quantity' => (float) $l['quantity'], 'unit_price' => (float) $l['unit_price']];
                }
                $iid = po_record_invoice($po, $sub['invoice_number'], $sub['invoice_date'], $sub['due_on'], $sub['notes'], $lines);
                q("UPDATE supplier_invoice_submissions SET status = 'recorded', supplier_invoice_id = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ? WHERE id = ?",
                    [$iid, $_SESSION['uid'], now_utc(), now_utc(), $sub['id']]);
                audit('procurement.submission.recorded', null, ['type' => 'supplier_invoice_submission', 'id' => $sub['id']], ['status' => 'submitted'], ['status' => 'recorded']);
            });
            flash('success', "Invoice {$sub['invoice_number']} recorded.");
        } elseif ($action === 'reject_sub') {
            require_can('finance.manage');
            $sub = row("SELECT * FROM supplier_invoice_submissions WHERE id = ? AND order_id = ? AND farm_id = ? AND status = 'submitted'", [input_id('submission_id'), $po['id'], $fid])
                ?? fail('This invoice has already been dealt with.');
            $reason = input('reason', 500) ?? fail('Tell the supplier why: they see this.');
            q("UPDATE supplier_invoice_submissions SET status = 'rejected', reject_reason = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ? WHERE id = ?",
                [$reason, $_SESSION['uid'], now_utc(), now_utc(), $sub['id']]);
            audit('procurement.submission.rejected', null, ['type' => 'supplier_invoice_submission', 'id' => $sub['id']], ['status' => 'submitted'], ['status' => 'rejected', 'reason' => $reason]);
            flash('success', 'Invoice sent back to the supplier.');
        } elseif ($action === 'pay') {
            require_can('finance.manage');
            $inv = row('SELECT * FROM supplier_invoices WHERE id = ? AND order_id = ? AND farm_id = ?', [input_id('invoice_id'), $po['id'], $fid]) ?? fail('Unknown invoice.');
            $amount = input_num('amount') ?? fail('Give the amount paid.');
            supplier_invoice_pay($inv, $amount, input_id('account_id') ?? fail('Choose where the money came from.'), input_date('paid_on') ?? farm_today(),
                input_in('method', ['cash', 'mobile_money', 'bank', 'cheque']) ?? 'cash', input('reference', 100));
            flash('success', 'Payment of ' . money($amount) . ' recorded.');
        }
    }, 'po.php', ['id' => $po['id']]);
}

$po = farm_row('purchase_orders', $po['id']);
$supplier = row('SELECT * FROM suppliers WHERE id = ?', [$po['supplier_id']]);
$lines = po_lines($po);
$store = $po['delivery_location_id'] ? row('SELECT code, name FROM farm_locations WHERE id = ?', [$po['delivery_location_id']]) : null;
$dispatches = rows('SELECT * FROM supplier_dispatches WHERE order_id = ? AND farm_id = ? ORDER BY dispatched_on', [$po['id'], $fid]);
$deliveries = rows('SELECT d.*, l.code AS store, u.name AS who FROM deliveries d JOIN farm_locations l ON l.id = d.location_id LEFT JOIN users u ON u.id = d.received_by WHERE d.order_id = ? AND d.farm_id = ? ORDER BY d.received_on', [$po['id'], $fid]);
$subs = rows('SELECT * FROM supplier_invoice_submissions WHERE order_id = ? AND farm_id = ? ORDER BY created_at', [$po['id'], $fid]);
$invoices = rows('SELECT * FROM supplier_invoices WHERE order_id = ? AND farm_id = ? ORDER BY invoice_date', [$po['id'], $fid]);
$names = array_column(rows('SELECT id, name FROM users WHERE id IN (?, ?)', [$po['created_by'] ?? '', $po['approved_by'] ?? '']), 'name', 'id');

page_start('Purchase order ' . $po['code']);
echo '<p class="no-print"><a href="' . e(url('purchasing.php')) . '">← Purchasing</a> · <button type="button" data-print>Print</button></p>';
echo '<div class="card"><div class="row" style="justify-content:space-between;align-items:flex-start"><div><h2>' . e($supplier['name']) . '</h2><div class="muted">' . e($supplier['code'])
    . ' · ' . e($supplier['phone'] ?? '') . ' ' . e($supplier['email'] ?? '') . '</div></div><div style="text-align:right">' . badge($po['status'])
    . '<div class="muted">Wanted by ' . e(fdate($po['expected_on'])) . ($store ? ' · to ' . e($store['code']) : '') . '</div></div></div>';
echo '<dl class="facts"><dt>Drafted by</dt><dd>' . e($names[$po['created_by']] ?? '—') . '</dd><dt>Approved by</dt><dd>' . e($names[$po['approved_by']] ?? '—') . '</dd>'
    . '<dt>Sent</dt><dd>' . e(fdate($po['sent_at'])) . '</dd>'
    . '<dt>Supplier\'s answer</dt><dd>' . ($po['supplier_response'] ? badge($po['supplier_response'] === 'accepted' ? 'approved' : 'rejected') . ' ' . e(label($po['supplier_response'])) . ' · ' . e(fdate($po['supplier_responded_at']))
        . ($po['supplier_promised_on'] ? ' · promises ' . e(fdate($po['supplier_promised_on'])) : '') . ($po['supplier_note'] ? '<br>“' . e($po['supplier_note']) . '”' : '') : '—') . '</dd>'
    . ($po['notes'] ? '<dt>Notes</dt><dd>' . e($po['notes']) . '</dd>' : '') . ($po['cancel_reason'] ? '<dt>Cancelled</dt><dd>' . e($po['cancel_reason']) . '</dd>' : '') . '</dl>';
table($lines, [
    'Item' => fn ($l) => '<b>' . e($l['item']) . '</b>',
    '#Ordered' => fn ($l) => e(qty($l['quantity'], $l['unit'])),
    '#Supplier confirms' => fn ($l) => $l['confirmed_quantity'] !== null ? e(qty($l['confirmed_quantity'], $l['unit'])) : '—',
    '#On the way' => fn ($l) => $l['on_the_way'] ? e(qty($l['on_the_way'], $l['unit'])) : '—',
    '#Received' => fn ($l) => e(qty($l['received_quantity'], $l['unit'])),
    '#Invoiced' => fn ($l) => e(qty($l['invoiced_quantity'], $l['unit'])),
    '#Unit price' => fn ($l) => $GLOBALS['money'] ? e(money($l['unit_price'])) : '—',
    '#Amount' => fn ($l) => $GLOBALS['money'] ? e(money(round($l['quantity'] * $l['unit_price'], 2))) : '—',
]);
if ($money) {
    echo '<p style="text-align:right"><b>Total ' . e(money($po['total_amount'])) . '</b></p>';
}
echo '<div class="row no-print">';
if ($po['status'] === 'draft' && can('procurement.orders.approve')) {
    echo post_button('Approve', ['action' => 'approve'], 'primary');
}
if ($po['status'] === 'approved' && can('procurement.orders.manage')) {
    echo post_button('Send to supplier', ['action' => 'send'], 'primary');
}
if (in_array($po['status'], ['partially_received', 'received'], true) && can('procurement.orders.manage')) {
    echo post_button('Close (nothing more expected)', ['action' => 'close'], '', 'Close this order? Nothing more can be received on it.');
}
echo '</div></div>';

if ($dispatches) {
    echo '<div class="card"><h2>Sent by the supplier</h2>';
    table($dispatches, ['Dispatch' => fn ($d) => '<b>' . e($d['code']) . '</b>', 'Sent' => fn ($d) => e(fdate($d['dispatched_on'])), 'Expected' => fn ($d) => e(fdate($d['expected_on'])),
        'Delivery note' => fn ($d) => e($d['reference'] ?? '—'), 'Vehicle' => fn ($d) => e(trim(($d['vehicle'] ?? '') . ' ' . ($d['driver'] ?? '')) ?: '—'),
        'Note' => fn ($d) => e($d['note'] ?? ''), 'Status' => fn ($d) => badge($d['status'])]);
    echo '</div>';
}
echo '<div class="card"><h2>Deliveries received</h2>';
table($deliveries, ['Delivery' => fn ($d) => '<b>' . e($d['code']) . '</b>', 'Date' => fn ($d) => e(fdate($d['received_on'])), 'Store' => fn ($d) => e($d['store']),
    'Delivery note' => fn ($d) => e($d['supplier_reference'] ?? '—'), 'Received by' => fn ($d) => e($d['who'] ?? '—')], 'Nothing received yet.');
echo '</div>';

if ($subs) {
    echo '<div class="card"><h2>Invoices sent through the portal</h2>';
    table($subs, ['Invoice' => fn ($x) => '<b>' . e($x['invoice_number']) . '</b> <span class="muted">' . e($x['code']) . '</span>', 'Date' => fn ($x) => e(fdate($x['invoice_date'])),
        '#Amount' => fn ($x) => $GLOBALS['money'] ? e(money($x['amount'])) : '—', 'Status' => fn ($x) => badge($x['status']) . ($x['reject_reason'] ? ' <span class="muted">' . e($x['reject_reason']) . '</span>' : ''),
        '' => fn ($x) => $x['status'] === 'submitted' && can('finance.manage') ? post_button('Record', ['action' => 'record_sub', 'submission_id' => $x['id']], 'small primary')
            . '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="action" value="reject_sub"><input type="hidden" name="submission_id" value="' . e($x['id']) . '"><input name="reason" placeholder="Why not" style="max-width:150px"><button class="small danger">Send back</button></form>' : '']);
    echo '</div>';
}
echo '<div class="card"><h2>Invoices</h2>';
$payments = rows("SELECT * FROM payments WHERE farm_id = ? AND payable_type = 'supplier_invoice' AND payable_id IN (SELECT id FROM supplier_invoices WHERE order_id = ?) ORDER BY paid_on", [$fid, $po['id']]);
table($invoices, ['Invoice' => fn ($i) => '<b>' . e($i['invoice_number']) . '</b> <span class="muted">' . e($i['code']) . '</span>', 'Date' => fn ($i) => e(fdate($i['invoice_date'])), 'Due' => fn ($i) => e(fdate($i['due_on'])),
    '#Amount' => fn ($i) => $GLOBALS['money'] ? e(money($i['amount'])) : '—', '#Paid' => fn ($i) => $GLOBALS['money'] ? e(money($i['paid_amount'])) : '—', 'Status' => fn ($i) => badge($i['status'])], 'No invoices yet.');
if ($payments) {
    echo '<h3>Payments</h3>';
    table($payments, ['Payment' => fn ($p) => e($p['code']), 'Invoice' => fn ($p) => e($p['payable_code']), 'Date' => fn ($p) => e(fdate($p['paid_on'])),
        'Method' => fn ($p) => e(label($p['method'])) . ($p['reference'] ? ' · ' . e($p['reference']) : ''), '#Amount' => fn ($p) => e(money($p['amount']))]);
}
echo '</div>';

echo '<div class="grid no-print">';
if (in_array($po['status'], PO_OPEN, true) && can('procurement.deliveries.receive') && array_filter($lines, fn ($l) => $l['to_receive'] > 0)) {
    $stores = rows("SELECT id, code, name FROM farm_locations WHERE farm_id = ? AND deleted_at IS NULL ORDER BY kind <> 'store', code", [$fid]);
    $pending = array_filter($dispatches, fn ($d) => $d['status'] === 'dispatched');
    form_start('Receive goods', '', (bool) $pending);
    echo '<input type="hidden" name="action" value="receive"><div class="fields">'
        . field('Into store', '<select name="location_id" required>' . options($stores, 'id', fn ($l) => $l['code'] . ' ' . $l['name'], $po['delivery_location_id'], false) . '</select>')
        . field('Date', '<input type="date" name="received_on" value="' . e(farm_today()) . '">')
        . ($pending ? field('Against the supplier\'s dispatch', '<select name="dispatch_id">' . options($pending, 'id', fn ($d) => $d['code'] . ($d['reference'] ? ' · ' . $d['reference'] : '')) . '</select>') : '')
        . field('Delivery note number', '<input name="reference" maxlength="60">') . '</div>';
    foreach ($lines as $l) {
        if ($l['to_receive'] > 0) {
            echo '<div class="fields">' . field($l['item'] . ' (' . qty($l['to_receive'], $l['unit']) . ' expected)', '<input name="lines[' . e($l['id']) . '][quantity]" inputmode="decimal" value="' . e($l['on_the_way'] ? (float) min($l['on_the_way'], $l['to_receive']) : '') . '">')
                . field('Lot / batch number', '<input name="lines[' . e($l['id']) . '][lot_number]" maxlength="60">') . '</div>';
        }
    }
    echo field('Note', '<input name="note" maxlength="500">');
    form_end('Receive into stock');
}
if (can('finance.manage') && in_array($po['status'], ['partially_received', 'received', 'closed'], true) && array_filter($lines, fn ($l) => $l['to_invoice'] > 0)) {
    form_start('Record the supplier\'s invoice');
    echo '<input type="hidden" name="action" value="invoice"><div class="fields">' . field('Invoice number', '<input name="invoice_number" required maxlength="60">')
        . field('Invoice date', '<input type="date" name="invoice_date" value="' . e(farm_today()) . '">') . field('Due', '<input type="date" name="due_on">', 'Empty: from the supplier\'s payment terms.') . '</div>';
    foreach ($lines as $l) {
        if ($l['to_invoice'] > 0) {
            echo '<div class="fields">' . field($l['item'] . ' (' . qty($l['to_invoice'], $l['unit']) . ' to invoice)', '<input name="lines[' . e($l['id']) . '][quantity]" inputmode="decimal" value="' . e((float) $l['to_invoice']) . '">')
                . field('Unit price on the invoice', '<input name="lines[' . e($l['id']) . '][unit_price]" inputmode="decimal" value="' . e((float) $l['unit_price']) . '">') . '</div>';
        }
    }
    echo field('Notes', '<input name="notes" maxlength="500">');
    form_end('Record invoice');
}
$openInv = array_filter($invoices, fn ($i) => $i['status'] === 'recorded');
if ($openInv && can('finance.manage')) {
    $cash = rows('SELECT id, name FROM ledger_accounts WHERE farm_id = ? AND is_cash = 1 ORDER BY code', [$fid]);
    form_start('Pay the supplier');
    echo '<input type="hidden" name="action" value="pay"><div class="fields">'
        . field('Invoice', '<select name="invoice_id" required>' . options($openInv, 'id', fn ($i) => $i['invoice_number'] . ' · ' . money($i['amount'] - $i['paid_amount']) . ' due', null, false) . '</select>')
        . field('Amount', '<input name="amount" required inputmode="decimal" value="' . e(round((float) reset($openInv)['amount'] - (float) reset($openInv)['paid_amount'], 2)) . '">')
        . field('From', '<select name="account_id" required>' . options($cash, 'id', 'name', null, false) . '</select>') . field('Method', '<select name="method">' . enum_options(['cash', 'mobile_money', 'bank', 'cheque'], 'mobile_money') . '</select>')
        . field('Date', '<input type="date" name="paid_on" value="' . e(farm_today()) . '">') . field('Reference', '<input name="reference" maxlength="100">') . '</div>';
    form_end('Record payment');
}
if (in_array($po['status'], ['draft', 'approved', 'sent'], true) && can('procurement.orders.manage')) {
    form_start('Cancel this order');
    echo '<input type="hidden" name="action" value="cancel">' . field('Reason', '<input name="reason" required maxlength="500">');
    form_end('Cancel order');
}
echo '</div>';
page_end();
