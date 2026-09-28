<?php
/* Sales: orders, invoices with payments, shipments that end a batch's journey at the customer, customers and products. */
require __DIR__ . '/inc/bootstrap.php';

$farm = require_farm('sales.view');
$fid = $farm['id'];
$tab = input_in('tab', ['orders', 'invoices', 'customers', 'shipments', 'products']) ?? 'orders';
$money = can('finance.view') || can('sales.invoice');

if (is_post()) {
    $action = input('action', 20);
    handle(function () use ($action, $fid) {
        if ($action === 'customer') {
            require_can('customers.manage');
            $name = input('name', 150) ?? fail('The customer\'s name is required.');
            $email = input('email', 150);
            if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                fail('That email address does not look right.');
            }
            insert('customers', ['id' => uuid(), 'farm_id' => $fid, 'code' => next_code('customers', 'CUS'), 'name' => $name, 'contact_person' => input('contact_person', 120),
                'phone' => input('phone', 30), 'email' => $email, 'address' => input('address', 300), 'payment_terms_days' => input_num('payment_terms_days'), 'is_active' => 1,
                'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
            flash('success', "$name added.");
        } elseif ($action === 'invoice') {
            require_can('sales.invoice');
            $customer = farm_row('customers', input_id('customer_id'));
            $lines = [];
            foreach ((array) ($_POST['lines'] ?? []) as $l) {
                $desc = trim((string) ($l['description'] ?? ''));
                if ($desc === '') {
                    continue;
                }
                $q = num($l['quantity'] ?? null);
                $p = num($l['unit_price'] ?? null);
                ($q > 0 && $p !== null && $p >= 0) || fail("Check the quantity and price of \"$desc\".");
                $lines[] = ['description' => $desc, 'quantity' => $q, 'unit' => mb_substr(trim((string) ($l['unit'] ?? '')), 0, 20) ?: null, 'unit_price' => $p,
                    'account_id' => (string) ($l['account_id'] ?? '')];
            }
            $lines || fail('Add at least one line.');
            $id = customer_invoice_issue($customer, $lines, input_date('invoice_date') ?? farm_today(), input_date('due_on'), input('notes', 500));
            flash('success', 'Invoice issued.');
            redirect('invoice.php', ['id' => $id]);
        } elseif ($action === 'dispatch') {
            require_can('sales.fulfil');
            $customer = farm_row('customers', input_id('customer_id'));
            $batch = row("SELECT * FROM trace_batches WHERE id = ? AND farm_id = ? AND status = 'open' AND kind <> 'shipment'", [input_id('batch_id'), $fid]) ?? fail('Choose an open batch to ship.');
            ship_dispatch($customer, [['batch' => $batch, 'quantity' => input_num('quantity')]], ['destination' => input('destination', 300), 'vehicle' => input('vehicle', 60),
                'driver' => input('driver', 120), 'notes' => input('notes', 500)]);
            flash('success', 'Shipment dispatched.');
        } elseif ($action === 'deliver') {
            require_can('sales.fulfil');
            ship_deliver(farm_row('shipments', input_id('shipment_id')), input('received_by', 120) ?? fail('Who received it?'));
            flash('success', 'Delivered.');
        } elseif ($action === 'order') {
            require_can('sales.orders.create');
            $customer = farm_row('customers', input_id('customer_id'));
            $id = so_place($customer, (array) ($_POST['lines'] ?? []), ['requested_delivery_on' => input_date('requested_delivery_on'), 'delivery_address' => input('delivery_address', 300),
                'internal_note' => input('internal_note', 500)], 'internal');
            flash('success', 'Order recorded.');
            redirect('order.php', ['id' => $id]);
        } elseif ($action === 'product') {
            require_can('sales.pricing.manage');
            $name = input('name', 150) ?? fail('Name the product.');
            $unit = input('unit', 20) ?? fail('Give the unit it is sold in.');
            val('SELECT 1 FROM units WHERE code = ?', [$unit]) || fail('Unknown unit.');
            $price = input_num('list_price');
            ($price !== null && $price >= 0) || fail('Give the price.');
            $item = input_id('inventory_item_id');
            if ($item && !belongs('inventory_items', $item)) {
                fail('Unknown stock item.');
            }
            insert('products', ['id' => uuid(), 'farm_id' => $fid, 'code' => next_code('products', 'PRD'), 'name' => $name, 'description' => input('description', 1000),
                'category' => input('category', 60), 'unit' => $unit, 'list_price' => $price, 'currency' => current_farm()['currency'], 'min_order_quantity' => input_num('min_order_quantity'),
                'availability_note' => input('availability_note', 200), 'inventory_item_id' => $item, 'is_published' => input('is_published') ? 1 : 0, 'is_active' => 1,
                'created_by' => $_SESSION['uid'], 'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
            audit('sales.product.created', null, ['type' => 'product', 'id' => null], null, ['name' => $name, 'price' => $price]);
            flash('success', "$name added.");
        } elseif ($action === 'product_update') {
            require_can('sales.pricing.manage');
            $p = farm_row('products', input_id('product_id'));
            $price = input_num('list_price');
            ($price !== null && $price >= 0) || fail('Give the price.');
            q('UPDATE products SET list_price = ?, availability_note = ?, is_published = ?, is_active = ?, updated_at = ?, version = version + 1 WHERE id = ? AND farm_id = ?',
                [$price, input('availability_note', 200), input('is_published') ? 1 : 0, input('is_active') ? 1 : 0, now_utc(), $p['id'], $fid]);
            audit('sales.product.updated', null, ['type' => 'product', 'id' => $p['id']], ['price' => $p['list_price']], ['price' => $price, 'published' => (bool) input('is_published')]);
            flash('success', "{$p['name']} saved.");
        }
    }, 'sales.php', ['tab' => match ($action) { 'customer' => 'customers', 'invoice' => 'invoices', 'order' => 'orders', 'product', 'product_update' => 'products', default => 'shipments' }]);
}

page_start('Sales');
tabs(['orders' => 'Orders', 'invoices' => 'Invoices', 'shipments' => 'Shipments', 'customers' => 'Customers', 'products' => 'Products'], $tab);
$customers = rows('SELECT c.*, (SELECT p.name FROM party_links pl JOIN parties p ON p.id = pl.party_id WHERE pl.farm_id = c.farm_id AND pl.kind = \'customer\' AND pl.record_id = c.id AND pl.status = \'active\' LIMIT 1) AS portal
    FROM customers c WHERE c.farm_id = ? ORDER BY c.name', [$fid]);

if ($tab === 'orders') {
    $status = input_in('status', ['open', 'all']) ?? 'open';
    $orders = rows('SELECT o.*, c.name AS customer FROM sales_orders o JOIN customers c ON c.id = o.customer_id WHERE o.farm_id = ?'
        . ($status === 'open' ? " AND o.status IN ('requested','approved','invoiced','dispatched')" : '') . ' ORDER BY o.created_at DESC LIMIT 200', [$fid]);
    echo '<p class="row"><a class="btn' . ($status === 'open' ? ' primary' : '') . '" href="?tab=orders&status=open">Open</a><a class="btn' . ($status === 'all' ? ' primary' : '') . '" href="?tab=orders&status=all">All</a></p><div class="card">';
    table($orders, ['Order' => fn ($o) => '<a href="' . e(url('order.php', ['id' => $o['id']])) . '"><b>' . e($o['code']) . '</b></a>' . ($o['source'] === 'portal' ? ' <span class="badge">portal</span>' : ''),
        'Customer' => fn ($o) => e($o['customer']), 'Placed' => fn ($o) => e(fdate($o['created_at'])), 'Wanted by' => fn ($o) => e(fdate($o['requested_delivery_on'])),
        '#Total' => fn ($o) => e(money($o['total_amount'])), 'Status' => fn ($o) => badge($o['status'])], 'No orders.');
    echo '</div>';
    if (can('sales.orders.create')) {
        $products = rows('SELECT * FROM products WHERE farm_id = ? AND is_active = 1 ORDER BY name', [$fid]);
        form_start('Record an order');
        echo '<input type="hidden" name="action" value="order"><div class="fields">' . field('Customer', '<select name="customer_id" required>' . options(array_filter($customers, fn ($c) => $c['is_active']), 'id', 'name') . '</select>')
            . field('Wanted by', '<input type="date" name="requested_delivery_on">') . field('Deliver to', '<input name="delivery_address" maxlength="300">', 'Empty: the customer\'s address.') . '</div>';
        for ($i = 0; $i < 4; $i++) {
            echo '<div class="fields">' . field('Product', '<select name="lines[' . $i . '][product_id]">' . options($products, 'id', fn ($p) => $p['name'] . ' · ' . money($p['list_price']) . '/' . $p['unit']) . '</select>')
                . field('Quantity', '<input name="lines[' . $i . '][quantity]" inputmode="decimal">') . field('Price (empty: list price)', '<input name="lines[' . $i . '][unit_price]" inputmode="decimal">') . '</div>';
        }
        echo field('Internal note', '<input name="internal_note" maxlength="500">');
        form_end('Record order');
    }
} elseif ($tab === 'products') {
    $products = rows('SELECT p.*, i.name AS item FROM products p LEFT JOIN inventory_items i ON i.id = p.inventory_item_id WHERE p.farm_id = ? ORDER BY p.is_active DESC, p.name', [$fid]);
    echo '<div class="card"><p class="muted">Published products are what customers see and order in their portal, at these prices.</p>';
    table($products, ['Product' => fn ($p) => '<b>' . e($p['name']) . '</b> <span class="muted">' . e($p['code']) . '</span><div class="muted">' . e($p['description'] ?? '') . '</div>',
        '#Price' => fn ($p) => e(money($p['list_price'])) . ' / ' . e($p['unit']), '#Minimum' => fn ($p) => $p['min_order_quantity'] !== null ? e(qty($p['min_order_quantity'], $p['unit'])) : '—',
        'Availability' => fn ($p) => e($p['availability_note'] ?? '—'),
        'Shown' => fn ($p) => $p['is_active'] ? ($p['is_published'] ? badge('active') . ' in the portal' : '<span class="muted">farm only</span>') : badge('inactive'),
        '' => fn ($p) => can('sales.pricing.manage') ? '<details><summary class="muted">Change</summary><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="product_update"><input type="hidden" name="product_id" value="' . e($p['id']) . '">'
            . '<input name="list_price" inputmode="decimal" value="' . e((float) $p['list_price']) . '" aria-label="Price"><input name="availability_note" maxlength="200" placeholder="Availability" value="' . e($p['availability_note'] ?? '') . '">'
            . '<label class="row"><input type="checkbox" style="width:auto" name="is_published" value="1"' . ($p['is_published'] ? ' checked' : '') . '> In the portal</label>'
            . '<label class="row"><input type="checkbox" style="width:auto" name="is_active" value="1"' . ($p['is_active'] ? ' checked' : '') . '> Still sold</label><button class="small">Save</button></form></details>' : ''], 'No products yet.');
    echo '</div>';
    if (can('sales.pricing.manage')) {
        $units = rows('SELECT code, name FROM units WHERE is_active = 1 ORDER BY dimension, code');
        $items = rows('SELECT id, name FROM inventory_items WHERE farm_id = ? AND is_active = 1 ORDER BY name', [$fid]);
        form_start('Add a product');
        echo '<input type="hidden" name="action" value="product"><div class="fields">' . field('Name', '<input name="name" required maxlength="150">') . field('Category', '<input name="category" maxlength="60">')
            . field('Sold in', '<select name="unit" required>' . options($units, 'code', fn ($u) => $u['code'] . ' · ' . $u['name']) . '</select>') . field('Price per unit', '<input name="list_price" required inputmode="decimal">')
            . field('Minimum order', '<input name="min_order_quantity" inputmode="decimal">') . field('Availability', '<input name="availability_note" maxlength="200" placeholder="e.g. From July">')
            . field('Stock item (optional)', '<select name="inventory_item_id">' . options($items, 'id', 'name') . '</select>') . field('Description', '<input name="description" maxlength="1000">') . '</div>'
            . '<label class="row"><input type="checkbox" style="width:auto" name="is_published" value="1"> Show it to customers in the portal</label>';
        form_end('Add product');
    }
} elseif ($tab === 'invoices') {
    $inv = rows('SELECT i.*, c.name AS customer FROM customer_invoices i JOIN customers c ON c.id = i.customer_id WHERE i.farm_id = ? ORDER BY i.invoice_date DESC LIMIT 200', [$fid]);
    $open = array_filter($inv, fn ($i) => $i['status'] === 'issued');
    if ($money) {
        echo '<div class="kpis">' . kpi('Customers owe', e(money(array_sum(array_map(fn ($i) => $i['amount'] - $i['paid_amount'], $open)))))
            . kpi('Overdue', e(money(array_sum(array_map(fn ($i) => $i['due_on'] && $i['due_on'] < farm_today() ? $i['amount'] - $i['paid_amount'] : 0, $open))))) . '</div>';
    }
    echo '<div class="card">';
    table($inv, ['Invoice' => fn ($i) => '<a href="' . e(url('invoice.php', ['id' => $i['id']])) . '"><b>' . e($i['code']) . '</b></a>', 'Customer' => fn ($i) => e($i['customer']),
        'Date' => fn ($i) => e(fdate($i['invoice_date'])), 'Due' => fn ($i) => e(fdate($i['due_on'])), '#Amount' => fn ($i) => $money ? e(money($i['amount'])) : '—',
        '#Still due' => fn ($i) => $money && $i['status'] === 'issued' ? e(money($i['amount'] - $i['paid_amount'])) : '—', 'Status' => fn ($i) => badge($i['status'])], 'No invoices.');
    echo '</div>';
    if (can('sales.invoice')) {
        ensure_chart();
        $income = rows("SELECT id, code, name FROM ledger_accounts WHERE farm_id = ? AND type = 'income' AND is_active = 1 ORDER BY code", [$fid]);
        form_start('Write an invoice');
        echo '<input type="hidden" name="action" value="invoice"><div class="fields">' . field('Customer', '<select name="customer_id" required>' . options($customers, 'id', 'name') . '</select>')
            . field('Date', '<input type="date" name="invoice_date" value="' . e(farm_today()) . '">') . field('Due', '<input type="date" name="due_on">', 'Empty: from the customer\'s payment terms.') . '</div><h3>Lines</h3>';
        for ($i = 0; $i < 4; $i++) {
            echo '<div class="fields">' . field('Description', '<input name="lines[' . $i . '][description]" maxlength="300">') . field('Quantity', '<input name="lines[' . $i . '][quantity]" inputmode="decimal">')
                . field('Unit', '<input name="lines[' . $i . '][unit]" maxlength="20">') . field('Unit price', '<input name="lines[' . $i . '][unit_price]" inputmode="decimal">')
                . field('Kind of sale', '<select name="lines[' . $i . '][account_id]">' . options($income, 'id', fn ($a) => $a['code'] . ' ' . $a['name'], null, false) . '</select>') . '</div>';
        }
        echo field('Notes', '<input name="notes" maxlength="500">');
        form_end('Issue invoice');
    }
} elseif ($tab === 'customers') {
    echo '<div class="card">';
    table($customers, ['Code' => fn ($c) => e($c['code']), 'Customer' => fn ($c) => '<b>' . e($c['name']) . '</b><div class="muted">' . e($c['contact_person'] ?? '') . '</div>',
        'Phone' => fn ($c) => e($c['phone'] ?? '—'), 'Email' => fn ($c) => e($c['email'] ?? '—'), '#Terms (days)' => fn ($c) => e($c['payment_terms_days'] ?? '—'),
        'Portal' => fn ($c) => $c['portal'] ? '<span class="badge ok">' . e($c['portal']) . '</span>' : (can('customers.manage') ? '<a href="' . e(url('portal-access.php', ['kind' => 'customer', 'record' => $c['id']])) . '">Invite</a>' : '—')], 'No customers.');
    echo '</div>';
    if (can('customers.manage')) {
        form_start('Add a customer');
        echo '<input type="hidden" name="action" value="customer"><div class="fields">' . field('Name', '<input name="name" required maxlength="150">') . field('Contact person', '<input name="contact_person" maxlength="120">')
            . field('Phone', '<input name="phone" maxlength="30">') . field('Email', '<input type="email" name="email" maxlength="150">') . field('Address', '<input name="address" maxlength="300">')
            . field('Payment terms (days)', '<input name="payment_terms_days" inputmode="numeric">') . '</div>';
        form_end('Add customer');
    }
} else {
    $ships = rows('SELECT s.*, c.name AS customer, b.batch_code, (SELECT GROUP_CONCAT(pb.batch_code) FROM shipment_lines l JOIN trace_batches pb ON pb.id = l.trace_batch_id WHERE l.shipment_id = s.id) AS goods
        FROM shipments s JOIN customers c ON c.id = s.customer_id JOIN trace_batches b ON b.id = s.trace_batch_id WHERE s.farm_id = ? ORDER BY s.dispatched_at DESC', [$fid]);
    echo '<div class="card">';
    table($ships, ['Shipment' => fn ($s) => '<b>' . e($s['code']) . '</b>', 'Customer' => fn ($s) => e($s['customer']) . '<div class="muted">' . e($s['destination'] ?? '') . '</div>',
        'Goods' => fn ($s) => '<span class="code">' . e($s['goods'] ?? '') . '</span>', 'Dispatched' => fn ($s) => e(fdate($s['dispatched_at'])),
        'Status' => fn ($s) => badge($s['status']) . ($s['status'] === 'delivered' ? ' <span class="muted">' . e($s['received_by']) . '</span>' : ''),
        'History' => fn ($s) => '<a class="code" href="' . e(url('batch.php', ['id' => $s['trace_batch_id']])) . '">' . e($s['batch_code']) . '</a>',
        '' => fn ($s) => $s['status'] === 'dispatched' && can('sales.fulfil') ? '<form method="post" class="row">' . csrf_field() . '<input type="hidden" name="action" value="deliver"><input type="hidden" name="shipment_id" value="' . e($s['id']) . '"><input name="received_by" placeholder="Received by" style="max-width:150px"><button class="small">Delivered</button></form>' : ''], 'No shipments.');
    echo '</div>';
    if (can('sales.fulfil')) {
        $batches = rows("SELECT id, batch_code, name, kind, quantity, unit FROM trace_batches WHERE farm_id = ? AND status = 'open' AND kind IN ('harvest','processed','packaged','animal_product','crop_lot') ORDER BY created_at DESC LIMIT 200", [$fid]);
        form_start('Dispatch a shipment');
        echo '<input type="hidden" name="action" value="dispatch"><div class="fields">' . field('Customer', '<select name="customer_id" required>' . options($customers, 'id', 'name') . '</select>')
            . field('Goods (batch)', '<select name="batch_id" required>' . options($batches, 'id', fn ($b) => $b['batch_code'] . ' · ' . ($b['name'] ?? label($b['kind'])) . ($b['quantity'] ? ' · ' . qty($b['quantity'], $b['unit']) : '')) . '</select>')
            . field('Quantity', '<input name="quantity" inputmode="decimal">') . field('Destination', '<input name="destination" maxlength="300">')
            . field('Vehicle', '<input name="vehicle" maxlength="60">') . field('Driver', '<input name="driver" maxlength="120">') . '</div>';
        form_end('Dispatch');
    }
}
page_end();
