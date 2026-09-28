<?php
/* Supplier portal: one purchase order. Answer it, tell the farm what you send, send your invoice. */
require __DIR__ . '/inc/bootstrap.php';

$links = require_portal('supplier');
$po = row("SELECT * FROM purchase_orders WHERE id = ? AND sent_at IS NOT NULL AND status IN ('sent','partially_received','received','closed','cancelled')", [input_id('id') ?? '']);
$link = portal_link_for($links, $po['farm_id'] ?? '', $po['supplier_id'] ?? '');
portal_enter($link);
$fid = $link['farm_id'];

if (is_post()) {
    $action = input('action', 20);
    handle(function () use ($action, $po, $fid, $link) {
        $name = $link['record']['name'];
        if ($action === 'respond') {
            $po['status'] === 'sent' || fail("{$po['code']} is " . label($po['status']) . '; answer an order before goods are received.');
            $decision = input_in('decision', ['accepted', 'rejected']) ?? fail('Accept or decline the order.');
            $note = input('note', 500);
            $decision === 'rejected' && !$note && fail('Say why you cannot supply this order.');
            $promised = input_date('promised_on');
            tx(function () use ($po, $decision, $note, $promised, $fid) {
                foreach (rows('SELECT * FROM purchase_order_lines WHERE order_id = ? FOR UPDATE', [$po['id']]) as $line) {
                    $c = $decision === 'accepted' ? (num($_POST['confirm'][$line['id']] ?? null) ?? (float) $line['quantity']) : null;
                    if ($c !== null && ($c < 0 || $c > (float) $line['quantity'] + 0.0005)) {
                        fail('You can confirm at most what was ordered.');
                    }
                    q('UPDATE purchase_order_lines SET confirmed_quantity = ? WHERE id = ?', [$c, $line['id']]);
                }
                q('UPDATE purchase_orders SET supplier_response = ?, supplier_responded_at = ?, supplier_responded_by = ?, supplier_promised_on = ?, supplier_note = ?, updated_at = ? WHERE id = ? AND farm_id = ?',
                    [$decision, now_utc(), $_SESSION['uid'], $decision === 'accepted' ? ($promised ?? $po['expected_on']) : null, $note, now_utc(), $po['id'], $fid]);
                audit("procurement.order.supplier_$decision", null, ['type' => 'purchase_order', 'id' => $po['id']], ['supplier_response' => $po['supplier_response']], ['supplier_response' => $decision, 'note' => $note]);
            });
            notify_holders(['procurement.orders.manage'], 'supplier_response', $decision === 'accepted' ? "$name accepted {$po['code']}" : "$name cannot supply {$po['code']}", $note, url('po.php', ['id' => $po['id']]));
            flash('success', $decision === 'accepted' ? 'Thank you: the farm sees that you accepted.' : 'The farm sees that you declined.');
        } elseif ($action === 'dispatch') {
            in_array($po['status'], ['sent', 'partially_received'], true) || fail("{$po['code']} is " . label($po['status']) . '.');
            $po['supplier_response'] === 'rejected' && fail('You declined this order. Accept it before dispatching.');
            $on = input_date('dispatched_on') ?? farm_today();
            $on <= farm_today() || fail('The dispatch date cannot be in the future.');
            $lines = [];
            foreach (po_lines($po) as $l) {
                $q = num($_POST['qty'][$l['id']] ?? null);
                if (!$q) {
                    continue;
                }
                $left = $l['to_receive'] - $l['on_the_way'];
                ($q > 0 && $q <= $left + 0.0005) || fail("More {$l['item']} than still expected: " . qty(max(0, $left), $l['unit']) . '.');
                $lines[$l['id']] = $q;
            }
            $lines || fail('Enter what you are sending.');
            tx(function () use ($po, $fid, $on, $lines) {
                $did = uuid();
                $code = next_code('supplier_dispatches', 'DSP');
                insert('supplier_dispatches', ['id' => $did, 'farm_id' => $fid, 'code' => $code, 'order_id' => $po['id'], 'status' => 'dispatched', 'dispatched_on' => $on,
                    'expected_on' => input_date('expected_on'), 'reference' => input('reference', 60), 'vehicle' => input('vehicle', 60), 'driver' => input('driver', 120),
                    'note' => input('note', 500), 'created_by' => $_SESSION['uid'], 'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
                foreach ($lines as $lineId => $q) {
                    insert('supplier_dispatch_lines', ['id' => uuid(), 'farm_id' => $fid, 'dispatch_id' => $did, 'order_line_id' => $lineId, 'quantity' => $q]);
                }
                audit('procurement.dispatch.announced', null, ['type' => 'supplier_dispatch', 'id' => $did], null, ['code' => $code, 'order' => $po['code']]);
            });
            notify_holders(['procurement.deliveries.receive', 'procurement.orders.manage'], 'supplier_dispatch', "$name dispatched goods for {$po['code']}", input('reference', 60), url('po.php', ['id' => $po['id']]));
            flash('success', 'The farm has been told what is on the way.');
        } elseif ($action === 'invoice') {
            in_array($po['status'], ['partially_received', 'received', 'closed'], true) || fail('Invoice what the farm has received: nothing is received on this order yet.');
            $number = input('invoice_number', 60) ?? fail('Give your invoice number.');
            $taken = val("SELECT 1 FROM supplier_invoices WHERE farm_id = ? AND supplier_id = ? AND invoice_number = ? AND status <> 'cancelled'", [$fid, $po['supplier_id'], $number])
                || val("SELECT 1 FROM supplier_invoice_submissions WHERE farm_id = ? AND supplier_id = ? AND invoice_number = ? AND status = 'submitted'", [$fid, $po['supplier_id'], $number]);
            $taken && fail("Invoice $number has already been sent to this farm.");
            $date = input_date('invoice_date') ?? farm_today();
            $lines = [];
            $total = 0.0;
            foreach (po_lines($po) as $l) {
                $q = num($_POST['inv_qty'][$l['id']] ?? null);
                if (!$q) {
                    continue;
                }
                $price = num($_POST['inv_price'][$l['id']] ?? null) ?? (float) $l['unit_price'];
                $open = $l['to_invoice'] - $l['invoice_waiting'];
                ($q > 0 && $q <= $open + 0.0005) || fail("More {$l['item']} than received and not yet invoiced: " . qty(max(0, $open), $l['unit']) . '.');
                $price >= 0 || fail('A price cannot be negative.');
                $lines[] = ['order_line_id' => $l['id'], 'quantity' => number_format($q, 3, '.', ''), 'unit_price' => number_format($price, 4, '.', '')];
                $total += round($q * $price, 2);
            }
            $lines || fail('Enter what you are invoicing.');
            $sid = uuid();
            insert('supplier_invoice_submissions', ['id' => $sid, 'farm_id' => $fid, 'code' => next_code('supplier_invoice_submissions', 'SUB'), 'order_id' => $po['id'], 'supplier_id' => $po['supplier_id'],
                'status' => 'submitted', 'invoice_number' => $number, 'invoice_date' => $date, 'due_on' => input_date('due_on'), 'amount' => $total, 'lines' => json_encode($lines),
                'notes' => input('notes', 500), 'submitted_by' => $_SESSION['uid'], 'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
            audit('procurement.submission.sent', null, ['type' => 'supplier_invoice_submission', 'id' => $sid], null, ['number' => $number, 'amount' => $total]);
            notify_holders(['finance.manage'], 'supplier_invoice', "$name sent invoice $number for {$po['code']}", money($total), url('po.php', ['id' => $po['id']]));
            flash('success', 'Invoice sent. The farm checks it against what it received.');
        }
    }, 'supplier-order.php', ['id' => $po['id']]);
}

$po = row('SELECT * FROM purchase_orders WHERE id = ?', [$po['id']]);
$lines = po_lines($po);
$dispatches = rows('SELECT * FROM supplier_dispatches WHERE order_id = ? AND farm_id = ? ORDER BY dispatched_on', [$po['id'], $fid]);
$deliveries = rows('SELECT d.code, d.received_on, d.supplier_reference FROM deliveries d WHERE d.order_id = ? AND d.farm_id = ? ORDER BY d.received_on', [$po['id'], $fid]);
$subs = rows('SELECT * FROM supplier_invoice_submissions WHERE order_id = ? AND farm_id = ? ORDER BY created_at', [$po['id'], $fid]);
$store = $po['delivery_location_id'] ? row('SELECT name FROM farm_locations WHERE id = ?', [$po['delivery_location_id']]) : null;
$farmRow = current_farm();
$ownerOrg = row('SELECT o.name FROM organizations o WHERE o.id = ?', [$farmRow['organization_id']]);

$sections = [];
ob_start();
echo '<div class="card"><div class="row" style="justify-content:space-between;align-items:flex-start"><div><h2>' . e($ownerOrg['name'] ?? $farmRow['name']) . '</h2><div class="muted">' . e($farmRow['name'])
    . ' · ' . e($farmRow['district'] ?? '') . '</div></div><div style="text-align:right">' . badge($po['status']) . '<div class="muted">Sent ' . e(fdate($po['sent_at'])) . '</div></div></div>'
    . '<dl class="facts"><dt>Wanted by</dt><dd>' . e(fdate($po['expected_on'])) . '</dd><dt>Deliver to</dt><dd>' . e($store['name'] ?? $farmRow['name']) . '</dd>'
    . '<dt>Your answer</dt><dd>' . ($po['supplier_response'] ? badge($po['supplier_response']) . ($po['supplier_promised_on'] ? ' · by ' . e(fdate($po['supplier_promised_on'])) : '') . ($po['supplier_note'] ? ' · “' . e($po['supplier_note']) . '”' : '') : '—') . '</dd>'
    . ($po['status'] === 'cancelled' ? '<dt>Cancelled</dt><dd>' . e($po['cancel_reason'] ?? '') . '</dd>' : '') . '</dl>';
table($lines, ['Item' => fn ($l) => '<b>' . e($l['item']) . '</b>', '#Ordered' => fn ($l) => e(qty($l['quantity'], $l['unit'])),
    '#You confirmed' => fn ($l) => $l['confirmed_quantity'] !== null ? e(qty($l['confirmed_quantity'], $l['unit'])) : '—',
    '#On the way' => fn ($l) => $l['on_the_way'] ? e(qty($l['on_the_way'], $l['unit'])) : '—', '#Received' => fn ($l) => e(qty($l['received_quantity'], $l['unit'])),
    '#Invoiced' => fn ($l) => e(qty($l['invoiced_quantity'], $l['unit'])), '#Unit price' => fn ($l) => e(money($l['unit_price'])),
    '#Amount' => fn ($l) => e(money(round($l['quantity'] * $l['unit_price'], 2)))]);
echo '<p style="text-align:right"><b>Total ' . e(money($po['total_amount'])) . '</b></p></div>';

echo '<div class="card"><h2>What you sent</h2>';
table($dispatches, ['Dispatch' => fn ($d) => '<b>' . e($d['code']) . '</b>', 'Sent' => fn ($d) => e(fdate($d['dispatched_on'])), 'Arrives' => fn ($d) => e(fdate($d['expected_on'])),
    'Delivery note' => fn ($d) => e($d['reference'] ?? '—'), 'Status' => fn ($d) => badge($d['status'])], 'Nothing announced yet.');
echo '<h3>Received by the farm</h3>';
table($deliveries, ['Delivery' => fn ($d) => e($d['code']), 'Date' => fn ($d) => e(fdate($d['received_on'])), 'Your delivery note' => fn ($d) => e($d['supplier_reference'] ?? '—')], 'Nothing received yet.');
echo '</div>';
if ($subs) {
    echo '<div class="card"><h2>Your invoices</h2>';
    table($subs, ['Invoice' => fn ($x) => '<b>' . e($x['invoice_number']) . '</b>', 'Date' => fn ($x) => e(fdate($x['invoice_date'])), '#Amount' => fn ($x) => e(money($x['amount'])),
        'Status' => fn ($x) => badge($x['status'] === 'recorded' ? 'accepted' : $x['status']) . ($x['reject_reason'] ? '<div class="muted">' . e($x['reject_reason']) . '</div>' : '')]);
    echo '</div>';
}

echo '<div class="grid">';
if ($po['status'] === 'sent') {
    form_start($po['supplier_response'] ? 'Change your answer' : 'Answer this order', '', !$po['supplier_response']);
    echo '<input type="hidden" name="action" value="respond"><div class="fields">' . field('Your answer', '<select name="decision"><option value="accepted">I can supply it</option><option value="rejected">I cannot supply it</option></select>')
        . field('Delivery by', '<input type="date" name="promised_on" value="' . e($po['supplier_promised_on'] ?? $po['expected_on'] ?? '') . '">') . field('Note to the farm', '<input name="note" maxlength="500">', 'Required if you cannot supply.') . '</div>';
    foreach ($lines as $l) {
        echo field($l['item'] . ': quantity you confirm', '<input name="confirm[' . e($l['id']) . ']" inputmode="decimal" value="' . e((float) ($l['confirmed_quantity'] ?? $l['quantity'])) . '">');
    }
    form_end('Send answer');
}
$toSend = array_filter($lines, fn ($l) => $l['to_receive'] - $l['on_the_way'] > 0);
if (in_array($po['status'], ['sent', 'partially_received'], true) && $po['supplier_response'] !== 'rejected' && $toSend) {
    form_start('Tell the farm what you are sending');
    echo '<input type="hidden" name="action" value="dispatch"><div class="fields">' . field('Sent on', '<input type="date" name="dispatched_on" value="' . e(farm_today()) . '">')
        . field('Arrives', '<input type="date" name="expected_on">') . field('Delivery note number', '<input name="reference" maxlength="60">')
        . field('Vehicle', '<input name="vehicle" maxlength="60">') . field('Driver', '<input name="driver" maxlength="120">') . '</div>';
    foreach ($toSend as $l) {
        $left = $l['to_receive'] - $l['on_the_way'];
        echo field($l['item'] . ' (' . qty($left, $l['unit']) . ' still expected)', '<input name="qty[' . e($l['id']) . ']" inputmode="decimal" value="' . e($left) . '">');
    }
    echo field('Note', '<input name="note" maxlength="500">');
    form_end('Send dispatch notice');
}
$toInvoice = array_filter($lines, fn ($l) => $l['to_invoice'] - $l['invoice_waiting'] > 0);
if (in_array($po['status'], ['partially_received', 'received', 'closed'], true) && $toInvoice) {
    form_start('Send your invoice');
    echo '<input type="hidden" name="action" value="invoice"><div class="fields">' . field('Invoice number', '<input name="invoice_number" required maxlength="60">')
        . field('Invoice date', '<input type="date" name="invoice_date" value="' . e(farm_today()) . '">') . field('Due', '<input type="date" name="due_on">') . '</div>';
    foreach ($toInvoice as $l) {
        $open = $l['to_invoice'] - $l['invoice_waiting'];
        echo '<div class="fields">' . field($l['item'] . ' (' . qty($open, $l['unit']) . ' received, not invoiced)', '<input name="inv_qty[' . e($l['id']) . ']" inputmode="decimal" value="' . e($open) . '">')
            . field('Unit price', '<input name="inv_price[' . e($l['id']) . ']" inputmode="decimal" value="' . e((float) $l['unit_price']) . '">') . '</div>';
    }
    echo field('Notes', '<input name="notes" maxlength="500">');
    form_end('Send invoice');
}
echo '</div>';
$html = ob_get_clean();
act_in_farm(null);
page_start('Purchase order ' . $po['code']);
echo '<p><a href="' . e(url('supplier.php')) . '">← Purchase orders</a></p>' . $html;
page_end();
