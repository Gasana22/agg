<?php
/*
 * Purchasing: purchase orders, what suppliers send, deliveries into stock and
 * supplier invoices. Used by the farm's pages and by the supplier portal.
 *
 * Ledger:
 *   delivery          Dr 1300 Inventory          / Cr 2100 Goods received, not invoiced (order price)
 *   supplier invoice  Dr 2100 (order price) + Dr/Cr 5200 price variance / Cr 2000 Accounts payable
 *   payment           Dr 2000 Accounts payable   / Cr cash or mobile money
 *
 * Order statuses: draft → approved → sent → partially_received → received → closed; cancelled.
 */

const PO_OPEN = ['approved', 'sent', 'partially_received'];

/** Lines of an order with the item, and what is on the way or waiting on invoices. */
function po_lines(array $order): array
{
    $lines = rows('SELECT l.*, i.name AS item, i.unit, i.code AS item_code FROM purchase_order_lines l JOIN inventory_items i ON i.id = l.item_id
        WHERE l.order_id = ? AND l.farm_id = ? ORDER BY i.name', [$order['id'], $order['farm_id']]);
    $onWay = [];
    foreach (rows("SELECT dl.order_line_id, SUM(dl.quantity) AS q FROM supplier_dispatch_lines dl JOIN supplier_dispatches d ON d.id = dl.dispatch_id
        WHERE d.order_id = ? AND d.status = 'dispatched' GROUP BY dl.order_line_id", [$order['id']]) as $r) {
        $onWay[$r['order_line_id']] = (float) $r['q'];
    }
    $waiting = [];
    foreach (rows("SELECT `lines` FROM supplier_invoice_submissions WHERE order_id = ? AND status = 'submitted'", [$order['id']]) as $s) {
        foreach (json_decode($s['lines'], true) ?: [] as $l) {
            $waiting[$l['order_line_id']] = ($waiting[$l['order_line_id']] ?? 0) + (float) $l['quantity'];
        }
    }
    foreach ($lines as &$l) {
        $l['on_the_way'] = $onWay[$l['id']] ?? 0.0;
        $l['invoice_waiting'] = $waiting[$l['id']] ?? 0.0;
        $l['to_receive'] = max(0, (float) $l['quantity'] - (float) $l['received_quantity']);
        $l['to_invoice'] = max(0, (float) $l['received_quantity'] - (float) $l['invoiced_quantity']);
    }
    return $lines;
}

function po_total(string $orderId): float
{
    return (float) val('SELECT COALESCE(SUM(ROUND(quantity * unit_price, 2)), 0) FROM purchase_order_lines WHERE order_id = ?', [$orderId]);
}

/**
 * Receive goods against an order. $qty: [order_line_id => quantity], $lots: [order_line_id => lot number].
 * Returns the delivery id.
 */
function po_receive(array $order, string $locationId, string $date, array $qty, array $lots = [], ?string $dispatchId = null, ?string $reference = null, ?string $note = null): string
{
    in_array($order['status'], PO_OPEN, true) || fail("{$order['code']} is " . label($order['status']) . '; only approved orders are received.');
    belongs('farm_locations', $locationId) || fail('Choose the store.');
    $date <= farm_today() || fail('The date cannot be in the future.');
    $qty = array_filter($qty, fn ($q) => $q > 0);
    $qty || fail('Enter the quantity received on at least one line.');
    $dispatch = null;
    if ($dispatchId) {
        $dispatch = row("SELECT * FROM supplier_dispatches WHERE id = ? AND order_id = ? AND farm_id = ? AND status = 'dispatched'", [$dispatchId, $order['id'], $order['farm_id']])
            ?? fail('Choose a dispatch of this order that has not been received.');
        $reference ??= $dispatch['reference'];
    }
    return tx(function () use ($order, $locationId, $date, $qty, $lots, $dispatch, $reference, $note) {
        $order = row('SELECT * FROM purchase_orders WHERE id = ? AND farm_id = ? FOR UPDATE', [$order['id'], farm_id()]);
        $supplier = row('SELECT * FROM suppliers WHERE id = ?', [$order['supplier_id']]);
        $lines = [];
        foreach (rows('SELECT l.*, i.name AS item_name FROM purchase_order_lines l JOIN inventory_items i ON i.id = l.item_id WHERE l.order_id = ? FOR UPDATE', [$order['id']]) as $l) {
            $lines[$l['id']] = $l;
        }
        $did = uuid();
        $code = next_code('deliveries', 'GRN');
        insert('deliveries', ['id' => $did, 'farm_id' => farm_id(), 'code' => $code, 'order_id' => $order['id'], 'location_id' => $locationId, 'received_on' => $date,
            'supplier_reference' => $reference, 'note' => $note, 'received_by' => $_SESSION['uid'], 'created_at' => gmdate('Y-m-d H:i:s.u')]);
        foreach ($qty as $lineId => $q) {
            $line = $lines[$lineId] ?? fail('That is not a line of this order.');
            $left = (float) $line['quantity'] - (float) $line['received_quantity'];
            $q <= $left + 0.0005 || fail("More {$line['item_name']} than ordered: " . qty($left) . ' still expected.');
            $item = row('SELECT * FROM inventory_items WHERE id = ?', [$line['item_id']]);
            $in = stock_receive($item, $locationId, $q, (float) $line['unit_price'], $date, '2100', $lots[$lineId] ?? null, null, "$code from {$supplier['name']}",
                ['source_type' => 'delivery', 'source_id' => $did, 'supplier_id' => $supplier['id'], 'memo' => "$code {$item['name']} from {$supplier['name']} ({$order['code']})"]);
            insert('delivery_lines', ['id' => uuid(), 'farm_id' => farm_id(), 'delivery_id' => $did, 'order_line_id' => $lineId, 'quantity' => $q,
                'lot_id' => $in['lot_id'], 'movement_id' => $in['movement_id']]);
            q('UPDATE purchase_order_lines SET received_quantity = received_quantity + ? WHERE id = ?', [$q, $lineId]);
        }
        $complete = !val('SELECT 1 FROM purchase_order_lines WHERE order_id = ? AND received_quantity + 0.0005 < quantity', [$order['id']]);
        q('UPDATE purchase_orders SET status = ?, updated_at = ?, version = version + 1 WHERE id = ?', [$complete ? 'received' : 'partially_received', now_utc(), $order['id']]);
        if ($dispatch) {
            q("UPDATE supplier_dispatches SET status = 'received', delivery_id = ?, received_at = ?, updated_at = ? WHERE id = ?", [$did, now_utc(), now_utc(), $dispatch['id']]);
        }
        audit('procurement.delivery.received', null, ['type' => 'delivery', 'id' => $did], null, ['code' => $code, 'order' => $order['code']]);
        return $did;
    });
}

/**
 * Record a supplier's invoice, matched line by line to what was received and
 * not yet invoiced. $lines: [order_line_id => ['quantity' => …, 'unit_price' => …]]. Returns the invoice id.
 */
function po_record_invoice(array $order, string $number, string $date, ?string $due, ?string $notes, array $lines): string
{
    in_array($order['status'], ['partially_received', 'received', 'closed'], true) || fail("Nothing has been received on {$order['code']} yet; invoices are matched to deliveries.");
    val("SELECT 1 FROM supplier_invoices WHERE farm_id = ? AND supplier_id = ? AND invoice_number = ? AND status <> 'cancelled'", [farm_id(), $order['supplier_id'], $number])
        && fail("Invoice $number from this supplier is already recorded.");
    $lines = array_filter($lines, fn ($l) => ($l['quantity'] ?? 0) > 0);
    $lines || fail('Enter the quantity invoiced on at least one line.');
    return tx(function () use ($order, $number, $date, $due, $notes, $lines) {
        ensure_chart();
        $order = row('SELECT * FROM purchase_orders WHERE id = ? AND farm_id = ? FOR UPDATE', [$order['id'], farm_id()]);
        $supplier = row('SELECT * FROM suppliers WHERE id = ?', [$order['supplier_id']]);
        $open = [];
        foreach (rows('SELECT l.*, i.name AS item_name FROM purchase_order_lines l JOIN inventory_items i ON i.id = l.item_id WHERE l.order_id = ? FOR UPDATE', [$order['id']]) as $l) {
            $open[$l['id']] = $l;
        }
        $iid = uuid();
        $grni = $total = 0.0;
        $rowsOut = [];
        foreach ($lines as $lineId => $l) {
            $line = $open[$lineId] ?? fail('That is not a line of this order.');
            $q = (float) $l['quantity'];
            $price = (float) ($l['unit_price'] ?? $line['unit_price']);
            $price >= 0 || fail('A price cannot be negative.');
            $left = (float) $line['received_quantity'] - (float) $line['invoiced_quantity'];
            $q <= $left + 0.0005 || fail("More {$line['item_name']} than received and not yet invoiced: " . qty($left) . '.');
            $grni += round($q * (float) $line['unit_price'], 2);
            $total += round($q * $price, 2);
            $rowsOut[] = ['id' => uuid(), 'farm_id' => farm_id(), 'invoice_id' => $iid, 'order_line_id' => $lineId, 'quantity' => $q, 'unit_price' => $price];
            q('UPDATE purchase_order_lines SET invoiced_quantity = invoiced_quantity + ? WHERE id = ?', [$q, $lineId]);
        }
        $total > 0 || fail('The invoice total must be above zero.');
        $variance = round($total - $grni, 2);
        $posting = [['account_id' => account_id('2100'), 'debit' => $grni], ['account_id' => account_id('2000'), 'credit' => $total]];
        if (abs($variance) >= 0.005) {
            $posting[] = $variance > 0 ? ['account_id' => account_id('5200'), 'debit' => $variance] : ['account_id' => account_id('5200'), 'credit' => -$variance];
        }
        $code = next_code('supplier_invoices', 'SINV', 4);
        $due ??= $supplier['payment_terms_days'] ? date('Y-m-d', strtotime("$date +{$supplier['payment_terms_days']} days")) : null;
        insert('supplier_invoices', ['id' => $iid, 'farm_id' => farm_id(), 'code' => $code, 'invoice_number' => $number, 'supplier_id' => $supplier['id'], 'order_id' => $order['id'],
            'invoice_date' => $date, 'due_on' => $due, 'amount' => $total, 'paid_amount' => 0, 'status' => 'recorded', 'notes' => $notes,
            'recorded_by' => $_SESSION['uid'], 'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        foreach ($rowsOut as $r) {
            insert('supplier_invoice_lines', $r);
        }
        $entry = ledger_post($date, 'supplier_invoice', $iid, "Invoice $number from {$supplier['name']} ({$order['code']})", $posting);
        q('UPDATE supplier_invoices SET ledger_entry_id = ? WHERE id = ?', [$entry, $iid]);
        $done = $order['status'] === 'received' && !val('SELECT 1 FROM purchase_order_lines WHERE order_id = ? AND invoiced_quantity + 0.0005 < quantity', [$order['id']]);
        if ($done) {
            q("UPDATE purchase_orders SET status = 'closed', updated_at = ? WHERE id = ?", [now_utc(), $order['id']]);
        }
        audit('procurement.invoice.recorded', null, ['type' => 'supplier_invoice', 'id' => $iid], null, ['code' => $code, 'number' => $number, 'amount' => $total]);
        return $iid;
    });
}

/** Pay a recorded supplier invoice (in part or in full). */
function supplier_invoice_pay(array $invoice, float $amount, string $cashAccountId, string $date, string $method, ?string $reference): void
{
    $invoice['status'] === 'recorded' || fail('This invoice is not open.');
    $due = (float) $invoice['amount'] - (float) $invoice['paid_amount'];
    ($amount > 0 && $amount <= $due + 0.004) || fail('The amount must be above zero and at most ' . money($due) . '.');
    val('SELECT 1 FROM ledger_accounts WHERE id = ? AND farm_id = ? AND is_cash = 1', [$cashAccountId, farm_id()]) || fail('Choose where the money came from.');
    tx(function () use ($invoice, $amount, $cashAccountId, $date, $method, $reference) {
        $pid = uuid();
        $supplier = val('SELECT name FROM suppliers WHERE id = ?', [$invoice['supplier_id']]);
        $entry = ledger_post($date, 'payment', $pid, "Payment of {$invoice['invoice_number']} to $supplier", [
            ['account_id' => account_id('2000'), 'debit' => $amount], ['account_id' => $cashAccountId, 'credit' => $amount]]);
        insert('payments', ['id' => $pid, 'farm_id' => farm_id(), 'code' => next_code('payments', 'PMT', 4), 'direction' => 'out', 'status' => 'posted',
            'payable_type' => 'supplier_invoice', 'payable_id' => $invoice['id'], 'payable_code' => $invoice['code'], 'party' => $supplier, 'amount' => $amount,
            'paid_on' => $date, 'method' => $method, 'account_id' => $cashAccountId, 'reference' => $reference, 'ledger_entry_id' => $entry,
            'recorded_by' => $_SESSION['uid'], 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        $paid = (float) $invoice['paid_amount'] + $amount;
        q('UPDATE supplier_invoices SET paid_amount = ?, status = ?, updated_at = ?, version = version + 1 WHERE id = ?',
            [$paid, $paid + 0.004 >= (float) $invoice['amount'] ? 'paid' : 'recorded', now_utc(), $invoice['id']]);
        audit('procurement.invoice.paid', null, ['type' => 'supplier_invoice', 'id' => $invoice['id']], null, ['amount' => $amount]);
    });
}
