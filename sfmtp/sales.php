<?php
/* Sales: customers, invoices with payments, and shipments that end a batch's journey at the customer. */
require __DIR__ . '/inc/bootstrap.php';

$farm = require_farm('sales.view');
$fid = $farm['id'];
$tab = input_in('tab', ['invoices', 'customers', 'shipments']) ?? 'invoices';
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
            ensure_chart();
            $customer = farm_row('customers', input_id('customer_id'));
            $date = input_date('invoice_date') ?? farm_today();
            $lines = [];
            foreach ((array) ($_POST['lines'] ?? []) as $l) {
                $desc = trim((string) ($l['description'] ?? ''));
                if ($desc === '') {
                    continue;
                }
                $q = (float) str_replace(',', '', (string) ($l['quantity'] ?? 0));
                $p = (float) str_replace(',', '', (string) ($l['unit_price'] ?? 0));
                ($q > 0 && $p >= 0) || fail("Check the quantity and price of \"$desc\".");
                $acc = $l['account_id'] ?? '';
                val("SELECT 1 FROM ledger_accounts WHERE id = ? AND farm_id = ? AND type = 'income'", [$acc, $fid]) || fail('Choose the kind of sale for each line.');
                $lines[] = ['description' => mb_substr($desc, 0, 300), 'quantity' => $q, 'unit' => mb_substr(trim((string) ($l['unit'] ?? '')), 0, 20) ?: null, 'unit_price' => $p, 'amount' => round($q * $p, 2), 'account_id' => $acc];
            }
            $lines || fail('Add at least one line.');
            $total = array_sum(array_column($lines, 'amount'));
            $total > 0 || fail('The invoice total must be above zero.');
            $id = uuid();
            tx(function () use ($id, $fid, $customer, $date, $lines, $total) {
                $code = next_code('customer_invoices', 'INV', 4);
                $posting = [['account_id' => account_id('1200'), 'debit' => $total]];
                foreach ($lines as $l) {
                    $posting[] = ['account_id' => $l['account_id'], 'credit' => $l['amount'], 'memo' => $l['description']];
                }
                $entry = ledger_post($date, 'customer_invoice', $id, "$code to {$customer['name']}", $posting);
                $due = input_date('due_on') ?? ($customer['payment_terms_days'] ? date('Y-m-d', strtotime("$date +{$customer['payment_terms_days']} days")) : null);
                insert('customer_invoices', ['id' => $id, 'farm_id' => $fid, 'code' => $code, 'status' => 'issued', 'customer_id' => $customer['id'], 'invoice_date' => $date, 'due_on' => $due,
                    'amount' => $total, 'paid_amount' => 0, 'notes' => input('notes', 500), 'ledger_entry_id' => $entry, 'created_by' => $_SESSION['uid'], 'issued_by' => $_SESSION['uid'],
                    'issued_at' => now_utc(), 'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
                foreach ($lines as $i => $l) {
                    insert('customer_invoice_lines', ['id' => uuid(), 'farm_id' => $fid, 'invoice_id' => $id, 'position' => $i + 1] + $l + ['created_at' => now_utc(), 'updated_at' => now_utc()]);
                }
                audit('sales.invoice.issued', null, ['type' => 'customer_invoice', 'id' => $id], null, ['code' => $code, 'amount' => $total]);
            });
            flash('success', 'Invoice issued for ' . money($total) . '.');
            redirect('invoice.php', ['id' => $id]);
        } elseif ($action === 'dispatch') {
            require_can('sales.fulfil');
            $customer = farm_row('customers', input_id('customer_id'));
            $batch = row("SELECT * FROM trace_batches WHERE id = ? AND farm_id = ? AND status = 'open' AND kind <> 'shipment'", [input_id('batch_id'), $fid]) ?? fail('Choose an open batch to ship.');
            $qty = input_num('quantity');
            $sid = uuid();
            tx(function () use ($sid, $fid, $customer, $batch, $qty) {
                $code = next_code('shipments', 'SHP');
                $ship = trace_create_batch('shipment', ['name' => "$code to {$customer['name']}", 'quantity' => $qty ?? $batch['quantity'], 'unit' => $batch['unit'], 'source_type' => 'shipment', 'source_id' => $sid]);
                trace_link($batch, $ship, 'ship', $qty ?? ($batch['quantity'] !== null ? (float) $batch['quantity'] : null), $batch['unit']);
                insert('shipments', ['id' => $sid, 'farm_id' => $fid, 'code' => $code, 'status' => 'dispatched', 'customer_id' => $customer['id'], 'trace_batch_id' => $ship['id'],
                    'destination' => input('destination', 300), 'vehicle' => input('vehicle', 60), 'driver' => input('driver', 120), 'notes' => input('notes', 500),
                    'dispatched_at' => gmdate('Y-m-d H:i:s'), 'dispatched_by' => $_SESSION['uid'], 'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
                insert('shipment_lines', ['id' => uuid(), 'farm_id' => $fid, 'shipment_id' => $sid, 'position' => 1, 'trace_batch_id' => $batch['id'], 'description' => $batch['name'],
                    'quantity' => $qty ?? $batch['quantity'], 'unit' => $batch['unit'], 'created_at' => now_utc()]);
                trace_record($ship['id'], 'dispatched', ['subject_type' => 'shipment', 'subject_id' => $sid, 'payload' => array_filter(['shipment' => $code, 'customer' => $customer['name'],
                    'customer_code' => $customer['code'], 'batches' => $batch['batch_code'], 'destination' => input('destination', 300), 'vehicle' => input('vehicle', 60)])]);
            });
            flash('success', 'Shipment dispatched.');
        } elseif ($action === 'deliver') {
            require_can('sales.fulfil');
            $s = farm_row('shipments', input_id('shipment_id'));
            $s['status'] === 'dispatched' || fail('This shipment is not on the road.');
            $by = input('received_by', 120) ?? fail('Who received it?');
            tx(function () use ($s, $by, $fid) {
                q("UPDATE shipments SET status = 'delivered', delivered_at = ?, received_by = ?, closed_by = ?, updated_at = ?, version = version + 1 WHERE id = ? AND farm_id = ?",
                    [gmdate('Y-m-d H:i:s'), $by, $_SESSION['uid'], now_utc(), $s['id'], $fid]);
                $customer = row('SELECT name FROM customers WHERE id = ?', [$s['customer_id']]);
                trace_record($s['trace_batch_id'], 'delivered', ['subject_type' => 'shipment', 'subject_id' => $s['id'], 'payload' => ['shipment' => $s['code'], 'customer' => $customer['name'], 'received_by' => $by]]);
                q("UPDATE trace_batches SET status = 'closed', updated_at = ? WHERE id = ? AND farm_id = ?", [now_utc(), $s['trace_batch_id'], $fid]);
            });
            flash('success', 'Delivered.');
        }
    }, 'sales.php', ['tab' => $action === 'customer' ? 'customers' : ($action === 'invoice' ? 'invoices' : 'shipments')]);
}

page_start('Sales');
tabs(['invoices' => 'Invoices', 'customers' => 'Customers', 'shipments' => 'Shipments'], $tab);
$customers = rows('SELECT * FROM customers WHERE farm_id = ? ORDER BY name', [$fid]);

if ($tab === 'invoices') {
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
        'Phone' => fn ($c) => e($c['phone'] ?? '—'), 'Email' => fn ($c) => e($c['email'] ?? '—'), '#Terms (days)' => fn ($c) => e($c['payment_terms_days'] ?? '—')], 'No customers.');
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
