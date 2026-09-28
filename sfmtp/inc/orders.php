<?php
/*
 * Selling: products, sales orders, customer invoices and shipments. Used by
 * the farm's pages and by the customer portal.
 *
 * Sales order statuses: requested → approved → invoiced → dispatched → delivered;
 * rejected; cancelled. Customers order at list price in the portal; staff
 * approve (above the farm's sales order limit only the owner), invoice from
 * the order, and ship from trace batches so the journey reaches the customer.
 */

const SO_OPEN = ['requested', 'approved', 'invoiced', 'dispatched'];

/**
 * Place an order. $lines: [['product_id' => …, 'quantity' => …, 'unit_price' => (staff only)]].
 * $source: 'portal' or 'internal'. Returns the order id.
 */
function so_place(array $customer, array $lines, array $data, string $source): string
{
    $customer['is_active'] || fail('This customer is not active.');
    $clean = [];
    foreach ($lines as $l) {
        $pid = uuid_or_null($l['product_id'] ?? null);
        $q = num($l['quantity'] ?? null);
        if ($pid === null || !$q) {
            continue;
        }
        $p = row('SELECT * FROM products WHERE id = ? AND farm_id = ? AND is_active = 1' . ($source === 'portal' ? ' AND is_published = 1' : ''), [$pid, farm_id()])
            ?? fail('Choose products this farm sells.');
        $q > 0 || fail('Quantities must be above zero.');
        if ($p['min_order_quantity'] !== null && $q + 0.0005 < (float) $p['min_order_quantity']) {
            fail("The minimum order of {$p['name']} is " . qty($p['min_order_quantity'], $p['unit']) . '.');
        }
        $price = $source === 'internal' && ($given = num($l['unit_price'] ?? null)) !== null ? $given : (float) $p['list_price'];
        $price >= 0 || fail('A price cannot be negative.');
        $clean[] = ['product' => $p, 'quantity' => $q, 'unit_price' => $price];
    }
    $clean || fail('Add at least one product with a quantity.');
    $id = uuid();
    tx(function () use ($id, $customer, $clean, $data, $source) {
        $code = next_code('sales_orders', 'SO', 4);
        $total = 0.0;
        insert('sales_orders', ['id' => $id, 'farm_id' => farm_id(), 'code' => $code, 'customer_id' => $customer['id'], 'status' => 'requested', 'source' => $source,
            'currency' => current_farm()['currency'], 'total_amount' => 0, 'requested_delivery_on' => $data['requested_delivery_on'] ?? null,
            'delivery_address' => $data['delivery_address'] ?? $customer['address'], 'customer_note' => $data['customer_note'] ?? null,
            'internal_note' => $source === 'internal' ? ($data['internal_note'] ?? null) : null, 'placed_by' => $_SESSION['uid'], 'version' => 1,
            'created_at' => now_utc(), 'updated_at' => now_utc()]);
        foreach ($clean as $i => $l) {
            $amount = round($l['quantity'] * $l['unit_price'], 2);
            $total += $amount;
            insert('sales_order_lines', ['id' => uuid(), 'farm_id' => farm_id(), 'order_id' => $id, 'position' => $i + 1, 'product_id' => $l['product']['id'],
                'description' => $l['product']['name'], 'quantity' => $l['quantity'], 'unit' => $l['product']['unit'], 'unit_price' => $l['unit_price'], 'amount' => $amount,
                'dispatched_quantity' => 0, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        }
        q('UPDATE sales_orders SET total_amount = ? WHERE id = ?', [$total, $id]);
        audit('sales.order.placed', null, ['type' => 'sales_order', 'id' => $id], null, ['code' => $code, 'customer' => $customer['code'], 'total' => $total, 'source' => $source]);
        $order = row('SELECT * FROM sales_orders WHERE id = ?', [$id]);
        if ($source === 'internal' && !so_needs_owner($order)) {
            so_mark_approved($order);
        } else {
            notify_holders(['sales.orders.create', 'sales.orders.approve'], 'sales_order', "New order $code from {$customer['name']}", money($total) . ' to approve.', url('order.php', ['id' => $id]));
        }
    });
    return $id;
}

function so_needs_owner(array $order): bool
{
    $limit = farm_settings()['approval_thresholds']['sales_order'] ?? null;
    return $limit && (float) $order['total_amount'] > (float) $limit;
}

function so_mark_approved(array $order): void
{
    q("UPDATE sales_orders SET status = 'approved', approved_by = ?, approved_at = ?, updated_at = ?, version = version + 1 WHERE id = ? AND farm_id = ?",
        [$_SESSION['uid'], now_utc(), now_utc(), $order['id'], farm_id()]);
    audit('sales.order.approved', null, ['type' => 'sales_order', 'id' => $order['id']], ['status' => 'requested'], ['status' => 'approved', 'total' => $order['total_amount']]);
}

function so_approve(array $order): void
{
    $order['status'] === 'requested' || fail("{$order['code']} is " . label($order['status']) . '.');
    (can('sales.orders.create') || can('sales.orders.approve')) || fail('You may not approve orders.');
    if (so_needs_owner($order)) {
        (can('sales.orders.approve') && is_owner()) || fail('Orders above ' . money(farm_settings()['approval_thresholds']['sales_order']) . ' need the owner.');
    }
    if ($order['source'] === 'internal' && $order['placed_by'] === $_SESSION['uid'] && !is_owner()) {
        fail('Someone other than whoever recorded this order must approve it.');
    }
    so_mark_approved($order);
}

/** Close an order that will not go ahead: rejected by the farm, or cancelled (by the farm, or by the customer while requested). */
function so_close(array $order, string $status, string $reason): void
{
    $allowed = $status === 'rejected' ? ['requested'] : ['requested', 'approved'];
    in_array($order['status'], $allowed, true) || fail("{$order['code']} is " . label($order['status']) . '; invoiced or dispatched orders are not cancelled.');
    $col = $status === 'rejected' ? 'reject_reason' : 'cancel_reason';
    q("UPDATE sales_orders SET status = ?, $col = ?, closed_by = ?, closed_at = ?, updated_at = ?, version = version + 1 WHERE id = ? AND farm_id = ?",
        [$status, $reason, $_SESSION['uid'], now_utc(), now_utc(), $order['id'], farm_id()]);
    audit("sales.order.$status", null, ['type' => 'sales_order', 'id' => $order['id']], ['status' => $order['status']], ['status' => $status, 'reason' => $reason]);
}

/**
 * Issue a customer invoice and post it: Dr 1200 Accounts receivable / Cr each line's income account.
 * $lines: [['description', 'quantity', 'unit', 'unit_price', 'account_id']]. Returns the invoice id.
 */
function customer_invoice_issue(array $customer, array $lines, string $date, ?string $due, ?string $notes): string
{
    ensure_chart();
    foreach ($lines as &$l) {
        $l['amount'] = round((float) $l['quantity'] * (float) $l['unit_price'], 2);
        val("SELECT 1 FROM ledger_accounts WHERE id = ? AND farm_id = ? AND type = 'income'", [$l['account_id'], farm_id()]) || fail('Choose the kind of sale for each line.');
    }
    unset($l);
    $total = array_sum(array_column($lines, 'amount'));
    $total > 0 || fail('The invoice total must be above zero.');
    $id = uuid();
    tx(function () use ($id, $customer, $lines, $date, $due, $notes, $total) {
        $code = next_code('customer_invoices', 'INV', 4);
        $posting = [['account_id' => account_id('1200'), 'debit' => $total]];
        foreach ($lines as $l) {
            $posting[] = ['account_id' => $l['account_id'], 'credit' => $l['amount'], 'memo' => $l['description']];
        }
        $entry = ledger_post($date, 'customer_invoice', $id, "$code to {$customer['name']}", $posting);
        $due ??= $customer['payment_terms_days'] ? date('Y-m-d', strtotime("$date +{$customer['payment_terms_days']} days")) : null;
        insert('customer_invoices', ['id' => $id, 'farm_id' => farm_id(), 'code' => $code, 'status' => 'issued', 'customer_id' => $customer['id'], 'invoice_date' => $date, 'due_on' => $due,
            'amount' => $total, 'paid_amount' => 0, 'notes' => $notes, 'ledger_entry_id' => $entry, 'created_by' => $_SESSION['uid'], 'issued_by' => $_SESSION['uid'],
            'issued_at' => now_utc(), 'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        foreach ($lines as $i => $l) {
            insert('customer_invoice_lines', ['id' => uuid(), 'farm_id' => farm_id(), 'invoice_id' => $id, 'position' => $i + 1, 'description' => mb_substr($l['description'], 0, 300),
                'quantity' => $l['quantity'], 'unit' => $l['unit'], 'unit_price' => $l['unit_price'], 'amount' => $l['amount'], 'account_id' => $l['account_id'],
                'created_at' => now_utc(), 'updated_at' => now_utc()]);
        }
        audit('sales.invoice.issued', null, ['type' => 'customer_invoice', 'id' => $id], null, ['code' => $code, 'amount' => $total]);
    });
    return $id;
}

/** Invoice an order at its prices. */
function so_invoice(array $order): string
{
    in_array($order['status'], ['approved', 'dispatched', 'delivered'], true) || fail("{$order['code']} is " . label($order['status']) . '; approve it first.');
    if ($order['customer_invoice_id'] && val("SELECT 1 FROM customer_invoices WHERE id = ? AND status <> 'void'", [$order['customer_invoice_id']])) {
        fail("{$order['code']} is already invoiced.");
    }
    return tx(function () use ($order) {
        ensure_chart();
        $customer = row('SELECT * FROM customers WHERE id = ?', [$order['customer_id']]);
        $default = account_id('4000');
        $lines = array_map(fn ($l) => ['description' => $l['description'], 'quantity' => $l['quantity'], 'unit' => $l['unit'], 'unit_price' => $l['unit_price'],
            'account_id' => $l['income_account_id'] ?: $default], rows('SELECT l.*, p.income_account_id FROM sales_order_lines l JOIN products p ON p.id = l.product_id WHERE l.order_id = ? ORDER BY l.position', [$order['id']]));
        $iid = customer_invoice_issue($customer, $lines, farm_today(), null, "Sales order {$order['code']}");
        q('UPDATE sales_orders SET customer_invoice_id = ?, status = ?, updated_at = ?, version = version + 1 WHERE id = ?',
            [$iid, $order['status'] === 'approved' ? 'invoiced' : $order['status'], now_utc(), $order['id']]);
        q('UPDATE shipments SET customer_invoice_id = ? WHERE sales_order_id = ? AND customer_invoice_id IS NULL', [$iid, $order['id']]);
        audit('sales.order.invoiced', null, ['type' => 'sales_order', 'id' => $order['id']], ['status' => $order['status']], ['invoice_id' => $iid]);
        return $iid;
    });
}

/**
 * Dispatch goods from trace batches. $goods: [['batch' => row, 'quantity' => ?float, 'order_line_id' => ?string, 'description' => ?string]].
 * The shipment is itself a batch linked from every batch it carries. Returns the shipment id.
 */
function ship_dispatch(array $customer, array $goods, array $meta, ?array $order = null): string
{
    $goods || fail('Choose what goes on the shipment.');
    $sid = uuid();
    tx(function () use ($sid, $customer, $goods, $meta, $order) {
        $code = next_code('shipments', 'SHP');
        $first = $goods[0];
        $ship = trace_create_batch('shipment', ['name' => "$code to {$customer['name']}", 'quantity' => count($goods) === 1 ? ($first['quantity'] ?? $first['batch']['quantity']) : null,
            'unit' => count($goods) === 1 ? $first['batch']['unit'] : null, 'source_type' => 'shipment', 'source_id' => $sid]);
        $invoice = $order && $order['customer_invoice_id'] && val("SELECT 1 FROM customer_invoices WHERE id = ? AND status <> 'void'", [$order['customer_invoice_id']]) ? $order['customer_invoice_id'] : null;
        insert('shipments', ['id' => $sid, 'farm_id' => farm_id(), 'code' => $code, 'status' => 'dispatched', 'customer_id' => $customer['id'], 'customer_invoice_id' => $invoice,
            'trace_batch_id' => $ship['id'], 'destination' => $meta['destination'] ?? null, 'vehicle' => $meta['vehicle'] ?? null, 'driver' => $meta['driver'] ?? null,
            'notes' => $meta['notes'] ?? null, 'dispatched_at' => gmdate('Y-m-d H:i:s'), 'dispatched_by' => $_SESSION['uid'], 'version' => 1,
            'created_at' => now_utc(), 'updated_at' => now_utc(), 'sales_order_id' => $order['id'] ?? null]);
        $codes = [];
        foreach ($goods as $i => $g) {
            $b = $g['batch'];
            $q = $g['quantity'] ?? ($b['quantity'] !== null ? (float) $b['quantity'] : null);
            trace_link($b, $ship, 'ship', $q, $b['unit']);
            insert('shipment_lines', ['id' => uuid(), 'farm_id' => farm_id(), 'shipment_id' => $sid, 'position' => $i + 1, 'trace_batch_id' => $b['id'],
                'description' => $g['description'] ?? $b['name'], 'quantity' => $q, 'unit' => $b['unit'], 'created_at' => now_utc(), 'sales_order_line_id' => $g['order_line_id'] ?? null]);
            if (!empty($g['order_line_id'])) {
                q('UPDATE sales_order_lines SET dispatched_quantity = dispatched_quantity + ?, updated_at = ? WHERE id = ?', [$q, now_utc(), $g['order_line_id']]);
            }
            $codes[] = $b['batch_code'];
        }
        trace_record($ship['id'], 'dispatched', ['subject_type' => 'shipment', 'subject_id' => $sid, 'payload' => array_filter(['shipment' => $code, 'customer' => $customer['name'],
            'customer_code' => $customer['code'], 'batches' => implode(',', $codes), 'destination' => $meta['destination'] ?? null, 'vehicle' => $meta['vehicle'] ?? null,
            'order' => $order['code'] ?? null])]);
        if ($order) {
            q("UPDATE sales_orders SET status = 'dispatched', updated_at = ?, version = version + 1 WHERE id = ?", [now_utc(), $order['id']]);
        }
        audit('sales.shipment.dispatched', null, ['type' => 'shipment', 'id' => $sid], null, ['code' => $code, 'order' => $order['code'] ?? null]);
    });
    return $sid;
}

/** Dispatch against an order: $picks [order_line_id => ['batch_id' => …, 'quantity' => …]]. */
function so_dispatch(array $order, array $picks, array $meta): string
{
    in_array($order['status'], ['approved', 'invoiced', 'dispatched'], true) || fail("{$order['code']} is " . label($order['status']) . '; approve it before dispatching.');
    $lines = [];
    foreach (rows('SELECT * FROM sales_order_lines WHERE order_id = ?', [$order['id']]) as $l) {
        $lines[$l['id']] = $l;
    }
    $goods = [];
    foreach ($picks as $lineId => $p) {
        $q = num($p['quantity'] ?? null);
        $bid = uuid_or_null($p['batch_id'] ?? null);
        if (!$q || !$bid) {
            continue;
        }
        $line = $lines[$lineId] ?? fail('That is not a line of this order.');
        $batch = row("SELECT * FROM trace_batches WHERE id = ? AND farm_id = ? AND status = 'open' AND kind <> 'shipment'", [$bid, farm_id()]) ?? fail('Choose open batches of this farm.');
        if ($batch['unit'] && $batch['unit'] !== $line['unit']) {
            fail("{$batch['batch_code']} is counted in {$batch['unit']} and the order in {$line['unit']}; choose a batch in {$line['unit']}.");
        }
        $left = (float) $line['quantity'] - (float) $line['dispatched_quantity'];
        $q <= $left + 0.0005 || fail("More {$line['description']} than still to send: " . qty($left, $line['unit']) . '.');
        $goods[] = ['batch' => $batch, 'quantity' => $q, 'order_line_id' => $lineId, 'description' => $line['description']];
    }
    $goods || fail('Choose a batch and a quantity for at least one line.');
    $customer = row('SELECT * FROM customers WHERE id = ?', [$order['customer_id']]);
    return ship_dispatch($customer, $goods, $meta + ['destination' => $order['delivery_address']], $order);
}

/** Record that a shipment arrived; the order is delivered once all of it has left and arrived. */
function ship_deliver(array $shipment, string $receivedBy, ?string $note = null): void
{
    $shipment['status'] === 'dispatched' || fail('This shipment is not on the road.');
    tx(function () use ($shipment, $receivedBy, $note) {
        q("UPDATE shipments SET status = 'delivered', delivered_at = ?, received_by = ?, closed_by = ?, updated_at = ?, version = version + 1 WHERE id = ? AND farm_id = ?",
            [gmdate('Y-m-d H:i:s'), $receivedBy, $_SESSION['uid'], now_utc(), $shipment['id'], farm_id()]);
        $customer = row('SELECT name FROM customers WHERE id = ?', [$shipment['customer_id']]);
        trace_record($shipment['trace_batch_id'], 'delivered', ['subject_type' => 'shipment', 'subject_id' => $shipment['id'],
            'payload' => array_filter(['shipment' => $shipment['code'], 'customer' => $customer['name'], 'received_by' => $receivedBy, 'notes' => $note])]);
        q("UPDATE trace_batches SET status = 'closed', updated_at = ? WHERE id = ? AND farm_id = ?", [now_utc(), $shipment['trace_batch_id'], farm_id()]);
        audit('sales.shipment.delivered', null, ['type' => 'shipment', 'id' => $shipment['id']], ['status' => 'dispatched'], ['status' => 'delivered', 'received_by' => $receivedBy]);
        if ($shipment['sales_order_id']) {
            $allSent = !val('SELECT 1 FROM sales_order_lines WHERE order_id = ? AND dispatched_quantity + 0.0005 < quantity', [$shipment['sales_order_id']]);
            $onRoad = val("SELECT 1 FROM shipments WHERE sales_order_id = ? AND status = 'dispatched'", [$shipment['sales_order_id']]);
            if ($allSent && !$onRoad) {
                q("UPDATE sales_orders SET status = 'delivered', delivered_at = ?, updated_at = ?, version = version + 1 WHERE id = ?", [now_utc(), now_utc(), $shipment['sales_order_id']]);
            }
        }
    });
}
