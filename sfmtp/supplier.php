<?php
/* Supplier portal: the purchase orders farms sent you, and your invoices with what has been paid. */
require __DIR__ . '/inc/bootstrap.php';

$links = require_portal('supplier');
$tab = input_in('tab', ['orders', 'invoices']) ?? 'orders';

$sections = [];
foreach ($links as $l) {
    portal_enter($l);
    ob_start();
    if ($tab === 'orders') {
        $orders = rows("SELECT o.id, o.code, o.status, o.sent_at, o.expected_on, o.total_amount, o.currency, o.supplier_response, o.supplier_promised_on,
            (SELECT COUNT(*) FROM supplier_dispatches d WHERE d.order_id = o.id AND d.status = 'dispatched') AS on_way
            FROM purchase_orders o WHERE o.farm_id = ? AND o.supplier_id = ? AND o.sent_at IS NOT NULL AND o.status IN ('sent','partially_received','received','closed','cancelled')
            ORDER BY o.sent_at DESC LIMIT 200", [$l['farm_id'], $l['record_id']]);
        table($orders, [
            'Order' => fn ($o) => '<a href="' . e(url('supplier-order.php', ['id' => $o['id']])) . '"><b>' . e($o['code']) . '</b></a>',
            'Received from farm' => fn ($o) => e(fdate($o['sent_at'])),
            'Wanted by' => fn ($o) => e(fdate($o['expected_on'])),
            '#Total' => fn ($o) => e(money($o['total_amount'], $o['currency'])),
            'Your answer' => fn ($o) => $o['supplier_response'] ? badge($o['supplier_response']) . ($o['supplier_promised_on'] ? ' <span class="muted">by ' . e(fdate($o['supplier_promised_on'])) . '</span>' : '')
                : ($o['status'] === 'sent' ? '<span class="badge warn">to answer</span>' : '—'),
            'Status' => fn ($o) => badge($o['status']) . ($o['on_way'] ? ' <span class="badge warn">on the way</span>' : ''),
        ], 'No orders from this farm yet.');
    } else {
        $subs = rows('SELECT x.*, o.code AS order_code FROM supplier_invoice_submissions x JOIN purchase_orders o ON o.id = x.order_id WHERE x.farm_id = ? AND x.supplier_id = ? ORDER BY x.created_at DESC',
            [$l['farm_id'], $l['record_id']]);
        $inv = rows('SELECT i.*, o.code AS order_code FROM supplier_invoices i JOIN purchase_orders o ON o.id = i.order_id WHERE i.farm_id = ? AND i.supplier_id = ? AND i.status <> \'cancelled\' ORDER BY i.invoice_date DESC',
            [$l['farm_id'], $l['record_id']]);
        $pays = rows("SELECT p.* FROM payments p JOIN supplier_invoices i ON i.id = p.payable_id WHERE p.farm_id = ? AND p.payable_type = 'supplier_invoice' AND p.status = 'posted' AND i.supplier_id = ? ORDER BY p.paid_on DESC",
            [$l['farm_id'], $l['record_id']]);
        echo '<div class="kpis">' . kpi('The farm owes you', e(money(array_sum(array_map(fn ($i) => $i['status'] === 'recorded' ? $i['amount'] - $i['paid_amount'] : 0, $inv)))))
            . kpi('Paid to you', e(money(array_sum(array_column($pays, 'amount'))))) . '</div>';
        echo '<h3>Invoices the farm recorded</h3>';
        table($inv, ['Your invoice' => fn ($i) => '<b>' . e($i['invoice_number']) . '</b>', 'Order' => fn ($i) => '<a href="' . e(url('supplier-order.php', ['id' => $i['order_id']])) . '">' . e($i['order_code']) . '</a>',
            'Date' => fn ($i) => e(fdate($i['invoice_date'])), 'Due' => fn ($i) => e(fdate($i['due_on'])), '#Amount' => fn ($i) => e(money($i['amount'])),
            '#Paid' => fn ($i) => e(money($i['paid_amount'])), '#Still owed' => fn ($i) => e(money($i['status'] === 'recorded' ? $i['amount'] - $i['paid_amount'] : 0)),
            'Status' => fn ($i) => badge($i['status'] === 'recorded' ? ((float) $i['paid_amount'] > 0 ? 'partly_paid' : 'unpaid') : $i['status'])], 'None yet.');
        echo '<h3>Invoices you sent</h3>';
        table($subs, ['Your invoice' => fn ($x) => '<b>' . e($x['invoice_number']) . '</b>', 'Order' => fn ($x) => e($x['order_code']), 'Date' => fn ($x) => e(fdate($x['invoice_date'])),
            '#Amount' => fn ($x) => e(money($x['amount'])), 'Status' => fn ($x) => badge($x['status'] === 'recorded' ? 'accepted' : $x['status'])
                . ($x['reject_reason'] ? '<div class="muted">' . e($x['reject_reason']) . '</div>' : '')], 'None sent through the portal.');
        echo '<h3>Payments</h3>';
        table($pays, ['Date' => fn ($p) => e(fdate($p['paid_on'])), 'For' => fn ($p) => e($p['payable_code']), 'Method' => fn ($p) => e(label($p['method'])) . ($p['reference'] ? ' · ' . e($p['reference']) : ''),
            '#Amount' => fn ($p) => e(money($p['amount']))], 'No payments yet.');
    }
    $sections[] = [$l, ob_get_clean()];
}
act_in_farm(null);

page_start($tab === 'orders' ? 'Purchase orders' : 'Invoices & payments');
foreach ($sections as [$l, $html]) {
    echo '<div class="card"><h2>' . e($l['farm_name']) . ' <span class="muted">as ' . e($l['record']['name']) . '</span></h2>' . $html . '</div>';
}
page_end();
