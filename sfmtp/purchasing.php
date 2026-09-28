<?php
/* Purchasing: purchase orders, suppliers, and supplier invoices (with those sent through the portal). */
require __DIR__ . '/inc/bootstrap.php';

$farm = require_farm('suppliers.view');
$fid = $farm['id'];
$tab = input_in('tab', ['orders', 'suppliers', 'invoices']) ?? 'orders';
$money = can('finance.view') || can('procurement.orders.manage') || can('inventory.values.view');

if (is_post()) {
    $action = input('action', 20);
    handle(function () use ($action, $fid) {
        if ($action === 'supplier') {
            require_can('suppliers.manage');
            $name = input('name', 150) ?? fail('The supplier\'s name is required.');
            $email = input('email', 150);
            if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                fail('That email address does not look right.');
            }
            insert('suppliers', ['id' => uuid(), 'farm_id' => $fid, 'code' => next_code('suppliers', 'SUP'), 'name' => $name, 'contact_person' => input('contact_person', 120),
                'phone' => input('phone', 30), 'email' => $email, 'address' => input('address', 300), 'tax_id' => input('tax_id', 40),
                'payment_terms_days' => input_num('payment_terms_days'), 'is_active' => 1, 'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
            audit('procurement.supplier.created', null, ['type' => 'supplier', 'id' => null], null, ['name' => $name]);
            flash('success', "$name added.");
        } elseif ($action === 'order') {
            require_can('procurement.orders.manage');
            $supplier = row('SELECT * FROM suppliers WHERE id = ? AND farm_id = ? AND is_active = 1', [input_id('supplier_id'), $fid]) ?? fail('Choose an active supplier.');
            $loc = input_id('delivery_location_id');
            if ($loc !== null && !belongs('farm_locations', $loc)) {
                fail('Choose the store the goods go to.');
            }
            $lines = [];
            foreach ((array) ($_POST['lines'] ?? []) as $l) {
                $item = uuid_or_null($l['item_id'] ?? null);
                if ($item === null) {
                    continue;
                }
                val('SELECT 1 FROM inventory_items WHERE id = ? AND farm_id = ? AND is_active = 1', [$item, $fid]) || fail('Choose items of this farm.');
                $q = num($l['quantity'] ?? null);
                $p = num($l['unit_price'] ?? null);
                ($q > 0 && $p !== null && $p >= 0) || fail('Give a quantity above zero and a price for each item.');
                $lines[] = ['item_id' => $item, 'quantity' => $q, 'unit_price' => $p, 'description' => mb_substr(trim((string) ($l['description'] ?? '')), 0, 200) ?: null];
            }
            $lines || fail('Add at least one item.');
            $id = uuid();
            tx(function () use ($id, $fid, $supplier, $loc, $lines) {
                $code = next_code('purchase_orders', 'PO');
                insert('purchase_orders', ['id' => $id, 'farm_id' => $fid, 'code' => $code, 'supplier_id' => $supplier['id'], 'status' => 'draft', 'expected_on' => input_date('expected_on'),
                    'delivery_location_id' => $loc, 'currency' => current_farm()['currency'], 'total_amount' => 0, 'notes' => input('notes', 1000), 'created_by' => $_SESSION['uid'],
                    'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
                foreach ($lines as $l) {
                    insert('purchase_order_lines', ['id' => uuid(), 'farm_id' => $fid, 'order_id' => $id] + $l);
                }
                q('UPDATE purchase_orders SET total_amount = ? WHERE id = ?', [po_total($id), $id]);
                audit('procurement.order.created', null, ['type' => 'purchase_order', 'id' => $id], null, ['code' => $code, 'supplier' => $supplier['code']]);
            });
            flash('success', 'Purchase order drafted. Approve it, then send it to the supplier.');
            redirect('po.php', ['id' => $id]);
        }
    }, 'purchasing.php', ['tab' => $action === 'supplier' ? 'suppliers' : 'orders']);
}

page_start('Purchasing');
tabs(['orders' => 'Purchase orders', 'suppliers' => 'Suppliers', 'invoices' => 'Supplier invoices'], $tab);
$suppliers = rows('SELECT s.*, (SELECT p.name FROM party_links pl JOIN parties p ON p.id = pl.party_id WHERE pl.farm_id = s.farm_id AND pl.kind = \'supplier\' AND pl.record_id = s.id AND pl.status = \'active\' LIMIT 1) AS portal
    FROM suppliers s WHERE s.farm_id = ? ORDER BY s.name', [$fid]);

if ($tab === 'orders') {
    $status = input_in('status', ['open', 'all']) ?? 'open';
    $orders = rows('SELECT o.*, s.name AS supplier,
        (SELECT COUNT(*) FROM supplier_dispatches d WHERE d.order_id = o.id AND d.status = \'dispatched\') AS on_way,
        (SELECT COUNT(*) FROM supplier_invoice_submissions x WHERE x.order_id = o.id AND x.status = \'submitted\') AS waiting
        FROM purchase_orders o JOIN suppliers s ON s.id = o.supplier_id WHERE o.farm_id = ?' . ($status === 'open' ? " AND o.status NOT IN ('closed','cancelled')" : '') . '
        ORDER BY o.created_at DESC LIMIT 200', [$fid]);
    echo '<p class="row"><a class="btn' . ($status === 'open' ? ' primary' : '') . '" href="?tab=orders&status=open">Open</a><a class="btn' . ($status === 'all' ? ' primary' : '') . '" href="?tab=orders&status=all">All</a></p>';
    echo '<div class="card">';
    table($orders, [
        'Order' => fn ($o) => '<a href="' . e(url('po.php', ['id' => $o['id']])) . '"><b>' . e($o['code']) . '</b></a>',
        'Supplier' => fn ($o) => e($o['supplier']),
        'Expected' => fn ($o) => e(fdate($o['supplier_promised_on'] ?? $o['expected_on'])),
        '#Total' => fn ($o) => $GLOBALS['money'] ? e(money($o['total_amount'])) : '—',
        'Supplier says' => fn ($o) => $o['supplier_response'] ? badge($o['supplier_response'] === 'accepted' ? 'approved' : 'rejected') : '<span class="muted">—</span>',
        'Status' => fn ($o) => badge($o['status']) . ($o['on_way'] ? ' <span class="badge warn">on the way</span>' : '') . ($o['waiting'] ? ' <span class="badge warn">invoice to check</span>' : ''),
    ], 'No purchase orders.');
    echo '</div>';
    if (can('procurement.orders.manage')) {
        $items = rows('SELECT id, name, unit FROM inventory_items WHERE farm_id = ? AND is_active = 1 ORDER BY name', [$fid]);
        $stores = rows("SELECT id, code, name FROM farm_locations WHERE farm_id = ? AND deleted_at IS NULL ORDER BY kind <> 'store', code", [$fid]);
        form_start('New purchase order');
        echo '<input type="hidden" name="action" value="order"><div class="fields">'
            . field('Supplier', '<select name="supplier_id" required>' . options(array_filter($suppliers, fn ($s) => $s['is_active']), 'id', 'name') . '</select>')
            . field('Wanted by', '<input type="date" name="expected_on">') . field('Deliver to', '<select name="delivery_location_id">' . options($stores, 'id', fn ($l) => $l['code'] . ' ' . $l['name']) . '</select>')
            . '</div><h3>Items</h3>';
        for ($i = 0; $i < 5; $i++) {
            echo '<div class="fields">' . field('Item', '<select name="lines[' . $i . '][item_id]">' . options($items, 'id', fn ($it) => $it['name'] . ' (' . $it['unit'] . ')') . '</select>')
                . field('Quantity', '<input name="lines[' . $i . '][quantity]" inputmode="decimal">') . field('Unit price', '<input name="lines[' . $i . '][unit_price]" inputmode="decimal">') . '</div>';
        }
        echo field('Notes (not shown to the supplier)', '<input name="notes" maxlength="1000">');
        form_end('Save draft');
    }
} elseif ($tab === 'suppliers') {
    echo '<div class="card">';
    table($suppliers, [
        'Code' => fn ($s) => e($s['code']),
        'Supplier' => fn ($s) => '<b>' . e($s['name']) . '</b><div class="muted">' . e($s['contact_person'] ?? '') . '</div>',
        'Phone' => fn ($s) => e($s['phone'] ?? '—'), 'Email' => fn ($s) => e($s['email'] ?? '—'),
        '#Terms (days)' => fn ($s) => e($s['payment_terms_days'] ?? '—'),
        'Portal' => fn ($s) => $s['portal'] ? '<span class="badge ok">' . e($s['portal']) . '</span>' : (can('suppliers.manage') ? '<a href="' . e(url('portal-access.php', ['kind' => 'supplier', 'record' => $s['id']])) . '">Invite</a>' : '—'),
        'Status' => fn ($s) => badge($s['is_active'] ? 'active' : 'inactive'),
    ], 'No suppliers.');
    echo '</div>';
    if (can('suppliers.manage')) {
        form_start('Add a supplier');
        echo '<input type="hidden" name="action" value="supplier"><div class="fields">' . field('Name', '<input name="name" required maxlength="150">') . field('Contact person', '<input name="contact_person" maxlength="120">')
            . field('Phone', '<input name="phone" maxlength="30">') . field('Email', '<input type="email" name="email" maxlength="150">') . field('Address', '<input name="address" maxlength="300">')
            . field('Tax number', '<input name="tax_id" maxlength="40">') . field('Payment terms (days)', '<input name="payment_terms_days" inputmode="numeric">') . '</div>';
        form_end('Add supplier');
    }
} else {
    $subs = rows("SELECT x.*, o.code AS order_code, s.name AS supplier FROM supplier_invoice_submissions x JOIN purchase_orders o ON o.id = x.order_id JOIN suppliers s ON s.id = x.supplier_id
        WHERE x.farm_id = ? AND x.status = 'submitted' ORDER BY x.created_at", [$fid]);
    $inv = rows('SELECT i.*, o.code AS order_code, s.name AS supplier FROM supplier_invoices i JOIN purchase_orders o ON o.id = i.order_id JOIN suppliers s ON s.id = i.supplier_id
        WHERE i.farm_id = ? ORDER BY i.invoice_date DESC LIMIT 200', [$fid]);
    $open = array_filter($inv, fn ($i) => $i['status'] === 'recorded');
    if ($money) {
        echo '<div class="kpis">' . kpi('We owe suppliers', e(money(array_sum(array_map(fn ($i) => $i['amount'] - $i['paid_amount'], $open)))))
            . kpi('Overdue', e(money(array_sum(array_map(fn ($i) => $i['due_on'] && $i['due_on'] < farm_today() ? $i['amount'] - $i['paid_amount'] : 0, $open)))))
            . kpi('Sent through the portal, to check', (string) count($subs)) . '</div>';
    }
    if ($subs) {
        echo '<div class="card"><h2>Sent by suppliers, waiting for you</h2>';
        table($subs, ['Invoice' => fn ($x) => '<b>' . e($x['invoice_number']) . '</b> <span class="muted">' . e($x['code']) . '</span>', 'Supplier' => fn ($x) => e($x['supplier']),
            'Order' => fn ($x) => '<a href="' . e(url('po.php', ['id' => $x['order_id']])) . '">' . e($x['order_code']) . '</a>', 'Date' => fn ($x) => e(fdate($x['invoice_date'])),
            '#Amount' => fn ($x) => $GLOBALS['money'] ? e(money($x['amount'])) : '—']);
        echo '</div>';
    }
    echo '<div class="card"><h2>Recorded</h2>';
    table($inv, ['Invoice' => fn ($i) => '<b>' . e($i['invoice_number']) . '</b> <span class="muted">' . e($i['code']) . '</span>', 'Supplier' => fn ($i) => e($i['supplier']),
        'Order' => fn ($i) => '<a href="' . e(url('po.php', ['id' => $i['order_id']])) . '">' . e($i['order_code']) . '</a>', 'Date' => fn ($i) => e(fdate($i['invoice_date'])), 'Due' => fn ($i) => e(fdate($i['due_on'])),
        '#Amount' => fn ($i) => $GLOBALS['money'] ? e(money($i['amount'])) : '—', '#Still to pay' => fn ($i) => $GLOBALS['money'] && $i['status'] === 'recorded' ? e(money($i['amount'] - $i['paid_amount'])) : '—',
        'Status' => fn ($i) => badge($i['status'] === 'recorded' ? 'issued' : $i['status'])], 'No supplier invoices.');
    echo '</div>';
}
page_end();
