<?php
/* Inventory: items, stock per store and lot at average cost, receipts and issues. */
require __DIR__ . '/inc/bootstrap.php';

$farm = require_farm('inventory.view');
$fid = $farm['id'];
$tab = input_in('tab', ['stock', 'items', 'movements']) ?? 'stock';
$values = can('inventory.values.view');

if (is_post()) {
    $action = input('action', 20);
    handle(function () use ($action, $fid) {
        if ($action === 'item') {
            require_can('inventory.manage');
            $name = input('name', 150) ?? fail('Name the item.');
            $cat = input_id('category_id');
            $cat && val('SELECT 1 FROM global_inventory_categories WHERE id = ?', [$cat]) || fail('Choose the category.');
            $unit = input('unit', 20) ?? fail('Give the unit.');
            val('SELECT 1 FROM units WHERE code = ?', [$unit]) || fail('Unknown unit.');
            insert('inventory_items', ['id' => uuid(), 'farm_id' => $fid, 'code' => next_code('inventory_items', 'ITM'), 'name' => $name, 'category_id' => $cat, 'unit' => $unit,
                'sku' => input('sku', 60), 'reorder_level' => input_num('reorder_level'), 'tracks_lots' => input('tracks_lots') ? 1 : 0, 'tracks_expiry' => input('tracks_expiry') ? 1 : 0,
                'is_active' => 1, 'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
            flash('success', "$name added.");
        } elseif ($action === 'receive') {
            require_can('inventory.stock.move');
            ensure_chart();
            $item = farm_row('inventory_items', input_id('item_id'));
            $loc = input_id('location_id');
            belongs('farm_locations', $loc) || fail('Choose the store.');
            $from = input_in('credit', ['3100', '2000', '1000', '1010']) ?? '3100';
            stock_receive($item, $loc, input_num('quantity') ?? fail('Give the quantity.'), input_num('unit_cost') ?? 0.0, input_date('received_on') ?? farm_today(),
                $from, input('lot_number', 60), input_date('expires_on'), input('note', 500));
            flash('success', 'Received ' . qty(input_num('quantity'), $item['unit']) . ' of ' . $item['name'] . '.');
        } elseif ($action === 'issue') {
            require_can('inventory.stock.move');
            ensure_chart();
            $bal = row('SELECT * FROM stock_balances WHERE id = ? AND farm_id = ?', [input_id('balance_id'), $fid]) ?? fail('Choose what to issue.');
            $item = farm_row('inventory_items', $bal['item_id']);
            [$st, $sid] = [null, null];
            if ($c = input_id('cycle_id')) {
                belongs('crop_cycles', $c) || fail('Unknown crop cycle.');
                [$st, $sid] = ['crop_cycle', $c];
            } elseif ($g = input_id('group_id')) {
                belongs('animal_groups', $g) || fail('Unknown group.');
                [$st, $sid] = ['animal_group', $g];
            }
            stock_issue($item, $bal['location_id'], $bal['lot_id'], input_num('quantity') ?? fail('Give the quantity.'), input_date('issued_on') ?? farm_today(), $st, $sid, input('note', 500));
            flash('success', 'Issued ' . qty(input_num('quantity'), $item['unit']) . ' of ' . $item['name'] . '.');
        }
    }, 'inventory.php', ['tab' => $action === 'item' ? 'items' : 'stock']);
}

page_start('Inventory');
tabs(['stock' => 'Stock', 'items' => 'Items', 'movements' => 'Movements'], $tab);
$items = rows('SELECT i.*, c.name AS category, (SELECT COALESCE(SUM(b.quantity), 0) FROM stock_balances b WHERE b.item_id = i.id) AS on_hand,
    (SELECT COALESCE(SUM(b.value), 0) FROM stock_balances b WHERE b.item_id = i.id) AS value
    FROM inventory_items i JOIN global_inventory_categories c ON c.id = i.category_id WHERE i.farm_id = ? ORDER BY i.name', [$fid]);

if ($tab === 'stock') {
    $bal = rows('SELECT b.*, i.name, i.code, i.unit, l.code AS store, lot.code AS lot_code, lot.lot_number, lot.expires_on FROM stock_balances b
        JOIN inventory_items i ON i.id = b.item_id JOIN farm_locations l ON l.id = b.location_id LEFT JOIN stock_lots lot ON lot.id = b.lot_id
        WHERE b.farm_id = ? AND b.quantity <> 0 ORDER BY i.name, l.code, lot.expires_on', [$fid]);
    $today = farm_today();
    if ($values) {
        echo '<div class="kpis">' . kpi('Stock value', e(money(array_sum(array_column($bal, 'value'))))) . kpi('Items in stock', (string) count(array_unique(array_column($bal, 'item_id')))) . '</div>';
    }
    echo '<div class="card">';
    $cols = ['Item' => fn ($b) => '<b>' . e($b['name']) . '</b> <span class="muted">' . e($b['code']) . '</span>', 'Store' => fn ($b) => e($b['store']),
        'Lot' => fn ($b) => e($b['lot_code'] ? $b['lot_code'] . ($b['lot_number'] ? " ({$b['lot_number']})" : '') : '—'),
        'Expires' => fn ($b) => $b['expires_on'] ? ($b['expires_on'] < $today ? '<span class="badge bad">' . e(fdate($b['expires_on'])) . '</span>' : e(fdate($b['expires_on']))) : '—',
        '#On hand' => fn ($b) => e(qty($b['quantity'], $b['unit']))];
    if ($values) {
        $cols['#Value'] = fn ($b) => e(money($b['value']));
    }
    table($bal, $cols, 'No stock.');
    echo '</div>';
    if (can('inventory.stock.move')) {
        $stores = rows('SELECT id, code, name FROM farm_locations WHERE farm_id = ? AND deleted_at IS NULL ORDER BY kind <> \'store\', code', [$fid]);
        $cycles = rows("SELECT c.id, c.code, cr.name FROM crop_cycles c JOIN crops cr ON cr.id = c.crop_id WHERE c.farm_id = ? AND c.stage <> 'closed' ORDER BY c.code", [$fid]);
        $groups = rows('SELECT id, code, name FROM animal_groups WHERE farm_id = ? AND is_active = 1 ORDER BY code', [$fid]);
        echo '<div class="grid">';
        form_start('Receive stock');
        echo '<input type="hidden" name="action" value="receive"><div class="fields">'
            . field('Item', '<select name="item_id" required>' . options(array_filter($items, fn ($i) => $i['is_active']), 'id', fn ($i) => $i['name'] . ' (' . $i['unit'] . ')') . '</select>')
            . field('Into store', '<select name="location_id" required>' . options($stores, 'id', fn ($l) => $l['code'] . ' ' . $l['name']) . '</select>')
            . field('Quantity', '<input name="quantity" required inputmode="decimal">') . field('Unit cost', '<input name="unit_cost" inputmode="decimal">')
            . field('Paid from / owed to', '<select name="credit"><option value="3100">Opening stock (already owned)</option><option value="2000">Owed to supplier</option><option value="1000">Paid cash</option><option value="1010">Paid by mobile money / bank</option></select>')
            . field('Date', '<input type="date" name="received_on" value="' . e(farm_today()) . '">') . field('Lot number', '<input name="lot_number" maxlength="60">', 'For items tracked by lot.')
            . field('Expires', '<input type="date" name="expires_on">') . field('Note', '<input name="note" maxlength="500">') . '</div>';
        form_end('Receive');
        form_start('Issue stock to work');
        echo '<input type="hidden" name="action" value="issue"><div class="fields">'
            . field('From', '<select name="balance_id" required>' . options(array_filter($bal, fn ($b) => $b['quantity'] > 0), 'id', fn ($b) => $b['name'] . ' · ' . $b['store'] . ($b['lot_code'] ? ' · ' . $b['lot_code'] : '') . ' · ' . qty($b['quantity'], $b['unit'])) . '</select>')
            . field('Quantity', '<input name="quantity" required inputmode="decimal">')
            . field('For crop cycle', '<select name="cycle_id">' . options($cycles, 'id', fn ($c) => $c['code'] . ' ' . $c['name']) . '</select>')
            . field('Or animal group', '<select name="group_id">' . options($groups, 'id', fn ($g) => $g['code'] . ' ' . $g['name']) . '</select>')
            . field('Date', '<input type="date" name="issued_on" value="' . e(farm_today()) . '">') . field('Note', '<input name="note" maxlength="500">') . '</div>';
        form_end('Issue');
        echo '</div>';
    }
} elseif ($tab === 'items') {
    echo '<div class="card">';
    $cols = ['Code' => fn ($i) => e($i['code']), 'Item' => fn ($i) => '<b>' . e($i['name']) . '</b>', 'Category' => fn ($i) => e($i['category']),
        '#On hand' => fn ($i) => e(qty($i['on_hand'], $i['unit'])), '#Reorder at' => fn ($i) => e(qty($i['reorder_level'], $i['unit'])),
        '' => fn ($i) => $i['reorder_level'] !== null && $i['on_hand'] <= $i['reorder_level'] ? '<span class="badge bad">low</span>' : ''];
    if ($values) {
        $cols['#Value'] = fn ($i) => e(money($i['value']));
    }
    table($items, $cols, 'No items.');
    echo '</div>';
    if (can('inventory.manage')) {
        $cats = rows('SELECT id, name FROM global_inventory_categories WHERE is_active = 1 ORDER BY name');
        $units = rows('SELECT code, name FROM units WHERE is_active = 1 ORDER BY dimension, code');
        form_start('Add an item');
        echo '<input type="hidden" name="action" value="item"><div class="fields">' . field('Name', '<input name="name" required maxlength="150">')
            . field('Category', '<select name="category_id" required>' . options($cats, 'id', 'name') . '</select>') . field('Unit', '<select name="unit" required>' . options($units, 'code', fn ($u) => $u['code'] . ' · ' . $u['name']) . '</select>')
            . field('Reorder when at', '<input name="reorder_level" inputmode="decimal">') . field('SKU', '<input name="sku" maxlength="60">')
            . field('Track lots', '<select name="tracks_lots"><option value="">No</option><option value="1">Yes (seed, drugs, chemicals)</option></select>')
            . field('Track expiry', '<select name="tracks_expiry"><option value="">No</option><option value="1">Yes</option></select>') . '</div>';
        form_end('Add item');
    }
} else {
    $mov = rows('SELECT m.*, i.name, i.unit, l.code AS store FROM stock_movements m JOIN inventory_items i ON i.id = m.item_id JOIN farm_locations l ON l.id = m.location_id
        WHERE m.farm_id = ? ORDER BY m.occurred_at DESC LIMIT 200', [$fid]);
    echo '<div class="card">';
    $cols = ['Date' => fn ($m) => e(fdate(substr($m['occurred_at'], 0, 10))), 'Item' => fn ($m) => e($m['name']), 'Store' => fn ($m) => e($m['store']), 'Type' => fn ($m) => e(label($m['type'])),
        '#Quantity' => fn ($m) => e(qty($m['quantity'], $m['unit'])), '#Balance after' => fn ($m) => e(qty($m['balance_after'])), 'For' => fn ($m) => e(label($m['subject_type'])), 'Note' => fn ($m) => e($m['note'] ?? '')];
    if ($values) {
        $cols['#Value'] = fn ($m) => e(money($m['value']));
    }
    table($mov, $cols, 'No movements.');
    echo '</div>';
}
page_end();
