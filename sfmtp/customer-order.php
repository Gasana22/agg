<?php
/* Customer portal: one order. Follow it, withdraw it before the farm approves, confirm a delivery. */
require __DIR__ . '/inc/bootstrap.php';

$links = require_portal('customer');
$so = row('SELECT * FROM sales_orders WHERE id = ?', [input_id('id') ?? '']);
$link = portal_link_for($links, $so['farm_id'] ?? '', $so['customer_id'] ?? '');
portal_enter($link);
$fid = $link['farm_id'];

if (is_post()) {
    $action = input('action', 20);
    handle(function () use ($action, $so, $fid, $link) {
        if ($action === 'cancel') {
            $so['status'] === 'requested' || fail("{$so['code']} is " . label($so['status']) . '; ask the farm to cancel it.');
            so_close($so, 'cancelled', input('reason', 300) ?? 'Withdrawn by the customer');
            notify_holders(['sales.orders.create', 'sales.orders.approve'], 'sales_order', "{$link['record']['name']} withdrew {$so['code']}", null, url('order.php', ['id' => $so['id']]));
            flash('success', 'Order withdrawn.');
        } elseif ($action === 'received') {
            $s = row("SELECT * FROM shipments WHERE id = ? AND farm_id = ? AND customer_id = ? AND sales_order_id = ?", [input_id('shipment_id'), $fid, $so['customer_id'], $so['id']]) ?? fail('Unknown shipment.');
            ship_deliver($s, input('received_by', 120) ?? current_user()['name'], input('note', 300));
            notify_holders(['sales.fulfil'], 'shipment_delivered', "{$link['record']['name']} received {$s['code']}", null, url('order.php', ['id' => $so['id']]));
            flash('success', 'Thank you: the farm sees that the goods arrived.');
        }
    }, 'customer-order.php', ['id' => $so['id']]);
}

$so = row('SELECT * FROM sales_orders WHERE id = ?', [$so['id']]);
$lines = rows('SELECT description, quantity, unit, unit_price, amount, dispatched_quantity FROM sales_order_lines WHERE order_id = ? AND farm_id = ? ORDER BY position', [$so['id'], $fid]);
$ships = rows('SELECT id, code, status, dispatched_at, delivered_at, vehicle, driver, received_by FROM shipments WHERE sales_order_id = ? AND farm_id = ? AND customer_id = ? ORDER BY dispatched_at', [$so['id'], $fid, $so['customer_id']]);
$invoice = $so['customer_invoice_id'] ? row("SELECT id, code, status, amount, paid_amount FROM customer_invoices WHERE id = ? AND customer_id = ? AND status IN ('issued','paid','void')", [$so['customer_invoice_id'], $so['customer_id']]) : null;
$steps = ['requested' => 'Placed', 'approved' => 'Confirmed by the farm', 'invoiced' => 'Invoiced', 'dispatched' => 'On the way', 'delivered' => 'Delivered'];

ob_start();
echo '<div class="card"><div class="row" style="justify-content:space-between"><h2>' . e($link['farm_name']) . '</h2><div>' . badge($so['status']) . '</div></div>';
if (isset($steps[$so['status']])) {
    $reached = true;
    echo '<p class="row">';
    foreach ($steps as $k => $t) {
        echo '<span class="badge ' . ($reached ? 'ok' : 'neutral') . '">' . e($t) . '</span>';
        if ($k === $so['status']) {
            $reached = false;
        }
    }
    echo '</p>';
}
echo '<dl class="facts"><dt>Placed</dt><dd>' . e(fdate($so['created_at'], true)) . '</dd><dt>Wanted by</dt><dd>' . e(fdate($so['requested_delivery_on'])) . '</dd>'
    . '<dt>Deliver to</dt><dd>' . e($so['delivery_address'] ?? '—') . '</dd>' . ($so['customer_note'] ? '<dt>Your note</dt><dd>' . e($so['customer_note']) . '</dd>' : '')
    . ($so['reject_reason'] ? '<dt>The farm declined</dt><dd>' . e($so['reject_reason']) . '</dd>' : '') . ($so['cancel_reason'] ? '<dt>Cancelled</dt><dd>' . e($so['cancel_reason']) . '</dd>' : '')
    . '<dt>Invoice</dt><dd>' . ($invoice ? '<a href="' . e(url('customer-invoice.php', ['id' => $invoice['id']])) . '">' . e($invoice['code']) . '</a> ' . badge($invoice['status']) : '—') . '</dd></dl>';
table($lines, ['Product' => fn ($l) => '<b>' . e($l['description']) . '</b>', '#Quantity' => fn ($l) => e(qty($l['quantity'], $l['unit'])), '#Sent' => fn ($l) => e(qty($l['dispatched_quantity'], $l['unit'])),
    '#Price' => fn ($l) => e(money($l['unit_price'])), '#Amount' => fn ($l) => e(money($l['amount']))]);
echo '<p style="text-align:right"><b>Total ' . e(money($so['total_amount'], $so['currency'])) . '</b></p></div>';
echo '<div class="card"><h2>Deliveries</h2>';
table($ships, ['Shipment' => fn ($s) => '<b>' . e($s['code']) . '</b>', 'Left the farm' => fn ($s) => e(fdate($s['dispatched_at'], true)), 'Vehicle' => fn ($s) => e(trim(($s['vehicle'] ?? '') . ' ' . ($s['driver'] ?? '')) ?: '—'),
    'Status' => fn ($s) => badge($s['status']) . ($s['delivered_at'] ? ' <span class="muted">' . e(fdate($s['delivered_at'], true)) . ' · ' . e($s['received_by'] ?? '') . '</span>' : ''),
    '' => fn ($s) => $s['status'] === 'dispatched' ? '<form method="post" class="row">' . csrf_field() . '<input type="hidden" name="action" value="received"><input type="hidden" name="shipment_id" value="' . e($s['id']) . '"><input name="received_by" placeholder="Received by" style="max-width:150px"><button class="small primary">It arrived</button></form>' : ''],
    'Nothing sent yet.');
echo '</div>';
if ($so['status'] === 'requested') {
    form_start('Withdraw this order');
    echo '<input type="hidden" name="action" value="cancel">' . field('Reason (optional)', '<input name="reason" maxlength="300">');
    form_end('Withdraw order');
}
$html = ob_get_clean();
act_in_farm(null);
page_start('Order ' . $so['code']);
echo '<p><a href="' . e(url('customer.php')) . '">← My orders</a></p>' . $html;
page_end();
