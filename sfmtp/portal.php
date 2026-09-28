<?php
/* Portal overview: every farm this person works with as a supplier or a customer. */
require __DIR__ . '/inc/bootstrap.php';

$user = require_login();
$GLOBALS['sfmtp_portal'] = true;
$links = portal_links();

$cards = [];
foreach ($links as $l) {
    portal_enter($l);
    $rid = $l['record_id'];
    $fid = $l['farm_id'];
    if ($l['kind'] === 'supplier') {
        $orders = rows("SELECT status, supplier_response FROM purchase_orders WHERE farm_id = ? AND supplier_id = ? AND sent_at IS NOT NULL AND status IN ('sent','partially_received','received','closed','cancelled')", [$fid, $rid]);
        $owed = (float) val("SELECT COALESCE(SUM(amount - paid_amount), 0) FROM supplier_invoices WHERE farm_id = ? AND supplier_id = ? AND status = 'recorded'", [$fid, $rid]);
        $cards[] = ['link' => $l, 'html' => '<div class="kpis">'
            . kpi('New orders to answer', (string) count(array_filter($orders, fn ($o) => $o['status'] === 'sent' && !$o['supplier_response'])), null, 'supplier.php')
            . kpi('Open orders', (string) count(array_filter($orders, fn ($o) => in_array($o['status'], ['sent', 'partially_received'], true) && $o['supplier_response'] !== 'rejected')), null, 'supplier.php')
            . kpi('On the way', (string) val("SELECT COUNT(*) FROM supplier_dispatches d JOIN purchase_orders o ON o.id = d.order_id WHERE d.farm_id = ? AND o.supplier_id = ? AND d.status = 'dispatched'", [$fid, $rid]))
            . kpi('The farm owes you', e(money($owed)), null, 'supplier.php?tab=invoices')
            . kpi('Invoices waiting for the farm', (string) val("SELECT COUNT(*) FROM supplier_invoice_submissions WHERE farm_id = ? AND supplier_id = ? AND status = 'submitted'", [$fid, $rid])) . '</div>'];
    } else {
        $orders = rows('SELECT status FROM sales_orders WHERE farm_id = ? AND customer_id = ?', [$fid, $rid]);
        $owe = (float) val("SELECT COALESCE(SUM(amount - paid_amount), 0) FROM customer_invoices WHERE farm_id = ? AND customer_id = ? AND status = 'issued'", [$fid, $rid]);
        $cards[] = ['link' => $l, 'html' => '<div class="kpis">'
            . kpi('Products on offer', (string) val('SELECT COUNT(*) FROM products WHERE farm_id = ? AND is_published = 1 AND is_active = 1', [$fid]), null, 'shop.php')
            . kpi('Orders in progress', (string) count(array_filter($orders, fn ($o) => in_array($o['status'], SO_OPEN, true))), null, 'customer.php')
            . kpi('Delivered', (string) count(array_filter($orders, fn ($o) => $o['status'] === 'delivered')))
            . kpi('You owe', e(money($owe)), null, 'customer.php?tab=invoices')
            . kpi('On the road to you', (string) val("SELECT COUNT(*) FROM shipments WHERE farm_id = ? AND customer_id = ? AND status = 'dispatched'", [$fid, $rid])) . '</div>'];
    }
}
act_in_farm(null);

page_start('Overview');
if (!$links) {
    echo '<div class="card"><p>You are not connected to any farm yet. A farm sends you an invitation link to open its supplier or customer portal.</p></div>';
}
foreach ($cards as $c) {
    $l = $c['link'];
    echo '<div class="card"><h2>' . e($l['farm_name']) . ' <span class="badge">' . e($l['kind'] === 'supplier' ? 'You supply them' : 'You buy from them') . '</span></h2>'
        . '<p class="muted">As ' . e($l['record']['name']) . ' · ' . e($l['record']['code']) . '</p>' . $c['html'] . '</div>';
}
page_end();
