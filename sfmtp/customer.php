<?php
/* Customer portal: your orders, invoices, and what you bought with its farm-to-you history. */
require __DIR__ . '/inc/bootstrap.php';

$links = require_portal('customer');
$tab = input_in('tab', ['orders', 'invoices', 'purchases']) ?? 'orders';

$sections = [];
foreach ($links as $l) {
    portal_enter($l);
    $fid = $l['farm_id'];
    $cid = $l['record_id'];
    ob_start();
    if ($tab === 'orders') {
        $orders = rows('SELECT id, code, status, created_at, requested_delivery_on, total_amount, currency FROM sales_orders WHERE farm_id = ? AND customer_id = ? ORDER BY created_at DESC LIMIT 200', [$fid, $cid]);
        table($orders, ['Order' => fn ($o) => '<a href="' . e(url('customer-order.php', ['id' => $o['id']])) . '"><b>' . e($o['code']) . '</b></a>', 'Placed' => fn ($o) => e(fdate($o['created_at'])),
            'Wanted by' => fn ($o) => e(fdate($o['requested_delivery_on'])), '#Total' => fn ($o) => e(money($o['total_amount'], $o['currency'])), 'Status' => fn ($o) => badge($o['status'])],
            'No orders yet. See what the farm offers under Order products.');
    } elseif ($tab === 'invoices') {
        $inv = rows("SELECT id, code, status, invoice_date, due_on, amount, paid_amount FROM customer_invoices WHERE farm_id = ? AND customer_id = ? AND status IN ('issued','paid','void') ORDER BY invoice_date DESC", [$fid, $cid]);
        echo '<div class="kpis">' . kpi('You owe', e(money(array_sum(array_map(fn ($i) => $i['status'] === 'issued' ? $i['amount'] - $i['paid_amount'] : 0, $inv))))) . '</div>';
        table($inv, ['Invoice' => fn ($i) => '<a href="' . e(url('customer-invoice.php', ['id' => $i['id']])) . '"><b>' . e($i['code']) . '</b></a>', 'Date' => fn ($i) => e(fdate($i['invoice_date'])),
            'Due' => fn ($i) => e(fdate($i['due_on'])) . ($i['status'] === 'issued' && $i['due_on'] && $i['due_on'] < farm_today() ? ' <span class="badge bad">overdue</span>' : ''),
            '#Amount' => fn ($i) => e(money($i['amount'])), '#Still due' => fn ($i) => e(money($i['status'] === 'issued' ? $i['amount'] - $i['paid_amount'] : 0)), 'Status' => fn ($i) => badge($i['status'])], 'No invoices.');
    } else {
        // Batches you received, with the approved public history (the same page a QR scan opens).
        $got = rows("SELECT s.code AS shipment, s.status, s.dispatched_at, sl.description, sl.quantity, sl.unit, b.batch_code, b.status AS batch_status,
            (SELECT q.code FROM trace_qr_codes q WHERE q.batch_id = b.id AND q.status = 'active' ORDER BY q.issued_at DESC LIMIT 1) AS qr
            FROM shipments s JOIN shipment_lines sl ON sl.shipment_id = s.id JOIN trace_batches b ON b.id = sl.trace_batch_id
            WHERE s.farm_id = ? AND s.customer_id = ? AND s.status IN ('dispatched','delivered') ORDER BY s.dispatched_at DESC LIMIT 200", [$fid, $cid]);
        table($got, ['Goods' => fn ($g) => '<b>' . e($g['description'] ?? '') . '</b> ' . e(qty($g['quantity'], $g['unit'])), 'Batch' => fn ($g) => '<span class="code">' . e($g['batch_code']) . '</span>'
                . ($g['batch_status'] === 'recalled' ? ' <span class="badge bad">recalled: do not use</span>' : ''),
            'Shipment' => fn ($g) => e($g['shipment']) . ' · ' . e(fdate($g['dispatched_at'])) . ' ' . badge($g['status']),
            'Where it comes from' => fn ($g) => $g['qr'] ? '<a href="' . e(url('q.php', ['c' => $g['qr']])) . '">See its history</a>' : '<span class="muted">Not published by the farm</span>'], 'Nothing received yet.');
    }
    $sections[] = [$l, ob_get_clean()];
}
act_in_farm(null);

page_start(['orders' => 'My orders', 'invoices' => 'Invoices', 'purchases' => 'What I bought'][$tab]);
foreach ($sections as [$l, $html]) {
    echo '<div class="card"><h2>' . e($l['farm_name']) . ' <span class="muted">as ' . e($l['record']['name']) . '</span></h2>' . $html . '</div>';
}
page_end();
