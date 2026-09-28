<?php
/* One sales order: approve or reject, invoice it, ship it from batches, record delivery. */
require __DIR__ . '/inc/bootstrap.php';

$farm = require_farm('sales.view');
$fid = $farm['id'];
$so = farm_row('sales_orders', input_id('id'));

if (is_post()) {
    $action = input('action', 20);
    handle(function () use ($action, $so) {
        if ($action === 'approve') {
            so_approve($so);
            flash('success', "{$so['code']} approved.");
        } elseif ($action === 'reject') {
            (can('sales.orders.create') || can('sales.orders.approve')) || require_can('sales.orders.approve');
            so_close($so, 'rejected', input('reason', 300) ?? fail('Tell the customer why: they see this.'));
            flash('success', "{$so['code']} rejected.");
        } elseif ($action === 'cancel') {
            require_can('sales.orders.create');
            so_close($so, 'cancelled', input('reason', 300) ?? fail('Say why it is cancelled.'));
            flash('success', "{$so['code']} cancelled.");
        } elseif ($action === 'invoice') {
            require_can('sales.invoice');
            $iid = so_invoice($so);
            flash('success', 'Invoice issued.');
            redirect('invoice.php', ['id' => $iid]);
        } elseif ($action === 'dispatch') {
            require_can('sales.fulfil');
            so_dispatch($so, (array) ($_POST['picks'] ?? []), ['vehicle' => input('vehicle', 60), 'driver' => input('driver', 120), 'notes' => input('notes', 500)]);
            flash('success', 'Shipment dispatched: the goods\' history now reaches the customer.');
        } elseif ($action === 'deliver') {
            require_can('sales.fulfil');
            $s = row('SELECT * FROM shipments WHERE id = ? AND sales_order_id = ? AND farm_id = ?', [input_id('shipment_id'), $so['id'], farm_id()]) ?? fail('Unknown shipment.');
            ship_deliver($s, input('received_by', 120) ?? fail('Who received it?'));
            flash('success', 'Delivered.');
        }
    }, 'order.php', ['id' => $so['id']]);
}

$so = farm_row('sales_orders', $so['id']);
$customer = row('SELECT * FROM customers WHERE id = ?', [$so['customer_id']]);
$lines = rows('SELECT * FROM sales_order_lines WHERE order_id = ? AND farm_id = ? ORDER BY position', [$so['id'], $fid]);
$ships = rows('SELECT s.*, b.batch_code FROM shipments s JOIN trace_batches b ON b.id = s.trace_batch_id WHERE s.sales_order_id = ? AND s.farm_id = ? ORDER BY s.dispatched_at', [$so['id'], $fid]);
$invoice = $so['customer_invoice_id'] ? row('SELECT * FROM customer_invoices WHERE id = ?', [$so['customer_invoice_id']]) : null;
$names = array_column(rows('SELECT id, name FROM users WHERE id IN (?, ?)', [$so['placed_by'] ?? '', $so['approved_by'] ?? '']), 'name', 'id');

page_start('Sales order ' . $so['code']);
echo '<p class="no-print"><a href="' . e(url('sales.php')) . '">← Sales</a></p>';
echo '<div class="card"><div class="row" style="justify-content:space-between;align-items:flex-start"><div><h2>' . e($customer['name']) . '</h2><div class="muted">' . e($customer['code'])
    . ' · ' . e($customer['phone'] ?? '') . '</div></div><div style="text-align:right">' . badge($so['status']) . '<div class="muted">' . ($so['source'] === 'portal' ? 'Placed in the portal' : 'Recorded by the farm') . '</div></div></div>'
    . '<dl class="facts"><dt>Placed</dt><dd>' . e(fdate($so['created_at'], true)) . ' · ' . e($names[$so['placed_by']] ?? '—') . '</dd><dt>Wanted by</dt><dd>' . e(fdate($so['requested_delivery_on'])) . '</dd>'
    . '<dt>Deliver to</dt><dd>' . e($so['delivery_address'] ?? '—') . '</dd>'
    . ($so['customer_note'] ? '<dt>Customer\'s note</dt><dd>' . e($so['customer_note']) . '</dd>' : '') . ($so['internal_note'] ? '<dt>Internal note</dt><dd>' . e($so['internal_note']) . '</dd>' : '')
    . '<dt>Approved</dt><dd>' . ($so['approved_at'] ? e(fdate($so['approved_at'])) . ' · ' . e($names[$so['approved_by']] ?? '') : '—') . '</dd>'
    . '<dt>Invoice</dt><dd>' . ($invoice ? '<a href="' . e(url('invoice.php', ['id' => $invoice['id']])) . '">' . e($invoice['code']) . '</a> ' . badge($invoice['status']) : '—') . '</dd>'
    . ($so['reject_reason'] ? '<dt>Rejected</dt><dd>' . e($so['reject_reason']) . '</dd>' : '') . ($so['cancel_reason'] ? '<dt>Cancelled</dt><dd>' . e($so['cancel_reason']) . '</dd>' : '') . '</dl>';
table($lines, ['Product' => fn ($l) => '<b>' . e($l['description']) . '</b>', '#Quantity' => fn ($l) => e(qty($l['quantity'], $l['unit'])), '#Sent' => fn ($l) => e(qty($l['dispatched_quantity'], $l['unit'])),
    '#Price' => fn ($l) => e(money($l['unit_price'])), '#Amount' => fn ($l) => e(money($l['amount']))]);
echo '<p style="text-align:right"><b>Total ' . e(money($so['total_amount'])) . '</b></p><div class="row">';
if ($so['status'] === 'requested' && (can('sales.orders.create') || can('sales.orders.approve'))) {
    echo post_button('Approve', ['action' => 'approve'], 'primary');
}
if (in_array($so['status'], ['approved', 'dispatched', 'delivered'], true) && can('sales.invoice') && !($invoice && $invoice['status'] !== 'void')) {
    echo post_button('Invoice this order', ['action' => 'invoice'], 'primary');
}
echo '</div></div>';

echo '<div class="card"><h2>Shipments</h2>';
table($ships, ['Shipment' => fn ($s) => '<b>' . e($s['code']) . '</b>', 'Dispatched' => fn ($s) => e(fdate($s['dispatched_at'], true)), 'Vehicle' => fn ($s) => e(trim(($s['vehicle'] ?? '') . ' ' . ($s['driver'] ?? '')) ?: '—'),
    'History' => fn ($s) => '<a class="code" href="' . e(url('batch.php', ['id' => $s['trace_batch_id']])) . '">' . e($s['batch_code']) . '</a>',
    'Status' => fn ($s) => badge($s['status']) . ($s['received_by'] ? ' <span class="muted">' . e($s['received_by']) . '</span>' : ''),
    '' => fn ($s) => $s['status'] === 'dispatched' && can('sales.fulfil') ? '<form method="post" class="row">' . csrf_field() . '<input type="hidden" name="action" value="deliver"><input type="hidden" name="shipment_id" value="' . e($s['id']) . '"><input name="received_by" placeholder="Received by" style="max-width:150px"><button class="small">Delivered</button></form>' : ''],
    'Nothing sent yet.');
echo '</div><div class="grid">';
if (in_array($so['status'], ['approved', 'invoiced', 'dispatched'], true) && can('sales.fulfil') && array_filter($lines, fn ($l) => $l['dispatched_quantity'] < $l['quantity'])) {
    $batches = rows("SELECT id, batch_code, name, kind, quantity, unit FROM trace_batches WHERE farm_id = ? AND status = 'open' AND kind IN ('harvest','processed','packaged','animal_product','crop_lot') ORDER BY created_at DESC LIMIT 200", [$fid]);
    form_start('Dispatch goods', '', true);
    echo '<input type="hidden" name="action" value="dispatch">';
    foreach ($lines as $l) {
        $left = (float) $l['quantity'] - (float) $l['dispatched_quantity'];
        if ($left <= 0) {
            continue;
        }
        $fit = array_filter($batches, fn ($b) => !$b['unit'] || $b['unit'] === $l['unit']);
        echo '<div class="fields">' . field($l['description'] . ' (' . qty($left, $l['unit']) . ' to send) — from batch', '<select name="picks[' . e($l['id']) . '][batch_id]">' . options($fit, 'id', fn ($b) => $b['batch_code'] . ' · ' . ($b['name'] ?? label($b['kind'])) . ($b['quantity'] ? ' · ' . qty($b['quantity'], $b['unit']) : '')) . '</select>')
            . field('Quantity', '<input name="picks[' . e($l['id']) . '][quantity]" inputmode="decimal" value="' . e($left) . '">') . '</div>';
    }
    echo '<div class="fields">' . field('Vehicle', '<input name="vehicle" maxlength="60">') . field('Driver', '<input name="driver" maxlength="120">') . field('Notes', '<input name="notes" maxlength="500">') . '</div>';
    form_end('Dispatch');
}
if ($so['status'] === 'requested' && (can('sales.orders.create') || can('sales.orders.approve'))) {
    form_start('Reject this order');
    echo '<input type="hidden" name="action" value="reject">' . field('Reason (the customer sees it)', '<input name="reason" required maxlength="300">');
    form_end('Reject');
}
if (in_array($so['status'], ['requested', 'approved'], true) && can('sales.orders.create')) {
    form_start('Cancel this order');
    echo '<input type="hidden" name="action" value="cancel">' . field('Reason', '<input name="reason" required maxlength="300">');
    form_end('Cancel order');
}
echo '</div>';
page_end();
