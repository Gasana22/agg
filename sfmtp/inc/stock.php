<?php
/*
 * Stock: balances per item, store and lot at average cost, and a movement
 * row for every change. Receipts and issues post to the ledger
 * (Inventory 1300 against the account the stock came from or went to).
 */

/** Lock and return the balance row, creating it at zero. */
function stock_balance_row(string $itemId, string $locationId, ?string $lotId): array
{
    $key = $lotId ?? '';
    q('INSERT IGNORE INTO stock_balances (id, farm_id, item_id, location_id, lot_id, lot_key, quantity, value, allow_negative, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, ?)', [uuid(), farm_id(), $itemId, $locationId, $lotId, $key, now_utc()]);
    return row('SELECT * FROM stock_balances WHERE farm_id = ? AND item_id = ? AND location_id = ? AND lot_key = ? FOR UPDATE', [farm_id(), $itemId, $locationId, $key]);
}

/**
 * Receive stock. $creditCode is where the value comes from:
 * 3100 opening balances, 2000 accounts payable, 1000 cash, 1010 mobile money.
 */
function stock_receive(array $item, string $locationId, float $quantity, float $unitCost, string $date, string $creditCode, ?string $lotNumber, ?string $expiresOn, ?string $note, array $ref = []): array
{
    if ($quantity <= 0 || $unitCost < 0) {
        fail('Quantity must be above zero and the unit cost cannot be negative.');
    }
    // $ref: source_type / source_id (e.g. a delivery), supplier_id, memo for the ledger.
    $source = $ref['source_type'] ?? 'manual_receipt';
    return tx(function () use ($item, $locationId, $quantity, $unitCost, $date, $creditCode, $lotNumber, $expiresOn, $note, $ref, $source) {
        $value = round($quantity * $unitCost, 2);
        $lotId = null;
        if ($item['tracks_lots']) {
            $batch = trace_create_batch('input_lot', ['name' => $item['name'] . ($lotNumber ? " (lot $lotNumber)" : ''), 'quantity' => $quantity, 'unit' => $item['unit'],
                'source_type' => 'stock_receipt'], ['occurred_at' => $date]);
            $lotId = uuid();
            insert('stock_lots', ['id' => $lotId, 'farm_id' => farm_id(), 'item_id' => $item['id'], 'code' => next_code('stock_lots', 'LOT', 4),
                'lot_number' => $lotNumber, 'expires_on' => $expiresOn, 'received_on' => $date, 'unit_cost' => number_format($unitCost, 4, '.', ''),
                'supplier_id' => $ref['supplier_id'] ?? null, 'trace_batch_id' => $batch['id'], 'source_type' => $source, 'source_id' => $ref['source_id'] ?? null, 'created_at' => now_utc()]);
        }
        $bal = stock_balance_row($item['id'], $locationId, $lotId);
        $newQty = (float) $bal['quantity'] + $quantity;
        q('UPDATE stock_balances SET quantity = ?, value = ?, updated_at = ? WHERE id = ?', [$newQty, (float) $bal['value'] + $value, now_utc(), $bal['id']]);

        $entry = $value > 0 ? ledger_post($date, $source === 'manual_receipt' ? 'stock_receipt' : $source, $ref['source_id'] ?? null, $ref['memo'] ?? "Stock in: {$item['name']}", [
            ['account_id' => account_id('1300'), 'debit' => $value],
            ['account_id' => account_id($creditCode), 'credit' => $value],
        ]) : null;
        $movementId = uuid();
        insert('stock_movements', ['id' => $movementId, 'farm_id' => farm_id(), 'item_id' => $item['id'], 'lot_id' => $lotId, 'location_id' => $locationId,
            'type' => 'receipt', 'quantity' => $quantity, 'unit_cost' => $unitCost, 'value' => $value, 'balance_after' => $newQty,
            'source_type' => $source, 'source_id' => $ref['source_id'] ?? null, 'ledger_entry_id' => $entry, 'note' => $note, 'occurred_at' => $date . ' 12:00:00.000000',
            'recorded_by' => $_SESSION['uid'] ?? null, 'created_at' => gmdate('Y-m-d H:i:s.u')]);
        audit('inventory.received', null, ['type' => 'inventory_item', 'id' => $item['id']], null, ['quantity' => $quantity, 'unit_cost' => $unitCost]);
        return ['movement_id' => $movementId, 'lot_id' => $lotId];
    });
}

/** Issue stock to work (feed, seed, fertilizer…) at average cost; posts Inputs used 5000. */
function stock_issue(array $item, string $locationId, ?string $lotId, float $quantity, string $date, ?string $subjectType, ?string $subjectId, ?string $note): void
{
    if ($quantity <= 0) {
        fail('Quantity must be above zero.');
    }
    tx(function () use ($item, $locationId, $lotId, $quantity, $date, $subjectType, $subjectId, $note) {
        $bal = stock_balance_row($item['id'], $locationId, $lotId);
        $have = (float) $bal['quantity'];
        if ($have + 0.0005 < $quantity && !(farm_settings()['allow_negative_stock'] ?? false)) {
            fail('Not enough stock: ' . qty($have, $item['unit']) . ' in that store.');
        }
        $avg = $have > 0 ? (float) $bal['value'] / $have : 0.0;
        $value = round($avg * $quantity, 2);
        $newQty = $have - $quantity;
        q('UPDATE stock_balances SET quantity = ?, value = ?, updated_at = ? WHERE id = ?', [$newQty, max(0, (float) $bal['value'] - $value), now_utc(), $bal['id']]);

        $entry = $value > 0 ? ledger_post($date, 'direct_issue', null, "Stock issued: {$item['name']}", [
            ['account_id' => account_id('5000'), 'debit' => $value, 'cost_center_type' => $subjectType, 'cost_center_id' => $subjectId],
            ['account_id' => account_id('1300'), 'credit' => $value],
        ]) : null;
        insert('stock_movements', ['id' => uuid(), 'farm_id' => farm_id(), 'item_id' => $item['id'], 'lot_id' => $lotId, 'location_id' => $locationId,
            'type' => 'issue', 'quantity' => -$quantity, 'unit_cost' => round($avg, 4), 'value' => -$value, 'balance_after' => $newQty,
            'source_type' => 'direct_issue', 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'ledger_entry_id' => $entry, 'note' => $note,
            'occurred_at' => $date . ' 12:00:00.000000', 'recorded_by' => $_SESSION['uid'] ?? null, 'created_at' => gmdate('Y-m-d H:i:s.u')]);

        if ($lotId && ($batchId = val('SELECT trace_batch_id FROM stock_lots WHERE id = ?', [$lotId]))) {
            trace_record($batchId, 'issued', ['occurred_at' => $date, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
                'payload' => ['quantity' => number_format($quantity, 3, '.', ''), 'unit' => $item['unit']]]);
        }
        audit('inventory.issued', null, ['type' => 'inventory_item', 'id' => $item['id']], null, ['quantity' => $quantity]);
    });
}
