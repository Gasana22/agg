<?php
/* One batch: where it came from, where it went, its full history; split, process, package, recall, publish. */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/publish.php';

$farm = require_farm('trace.batches.view');
$fid = $farm['id'];
$b = farm_row('trace_batches', input_id('id'));
$id = $b['id'];

if (is_post()) {
    $action = input('action', 20);
    handle(function () use ($action, $b, $fid, $id) {
        if (in_array($action, ['split', 'process', 'package'], true)) {
            require_can('trace.batches.create');
            $b['status'] === 'open' || fail('Only an open batch can be used.');
        }
        if ($action === 'split') {
            $parts = array_values(array_filter(array_map(fn ($v) => (float) str_replace(',', '', (string) $v), (array) ($_POST['parts'] ?? [])), fn ($v) => $v > 0));
            count($parts) >= 2 || fail('A split needs at least two parts.');
            if ($b['quantity'] !== null && array_sum($parts) > (float) $b['quantity'] + 0.0005) {
                fail('The parts add up to more than the batch (' . qty($b['quantity'], $b['unit']) . ').');
            }
            tx(function () use ($b, $parts) {
                foreach ($parts as $i => $q) {
                    $child = trace_create_batch($b['kind'], ['name' => ($b['name'] ?? $b['batch_code']) . ' · part ' . ($i + 1), 'quantity' => $q, 'unit' => $b['unit'], 'origin_plot_id' => $b['origin_plot_id']]);
                    trace_link($b, $child, 'split', $q, $b['unit']);
                }
                if ($b['quantity'] !== null && abs(array_sum($parts) - (float) $b['quantity']) < 0.0005) {
                    q("UPDATE trace_batches SET status = 'closed', updated_at = ? WHERE id = ? AND farm_id = ?", [now_utc(), $b['id'], farm_id()]);
                }
            });
            flash('success', 'Split into ' . count($parts) . ' batches.');
        } elseif ($action === 'process' || $action === 'package') {
            $name = input('name', 150) ?? fail('Name the new product.');
            $qty = input_num('quantity') ?? fail('Give the quantity produced.');
            $unit = input('unit', 20) ?? $b['unit'];
            $used = input_num('used') ?? ($b['quantity'] !== null ? (float) $b['quantity'] : null);
            if ($used !== null && $b['quantity'] !== null && $used > (float) $b['quantity'] + 0.0005) {
                fail('You cannot use more than the batch holds (' . qty($b['quantity'], $b['unit']) . ').');
            }
            $newId = tx(function () use ($action, $b, $name, $qty, $unit, $used) {
                $kind = $action === 'process' ? 'processed' : 'packaged';
                $new = trace_create_batch($kind, ['name' => $name, 'quantity' => $qty, 'unit' => $unit, 'origin_plot_id' => $b['origin_plot_id']]);
                trace_link($b, $new, $action, $used, $b['unit']);
                trace_record($new['id'], $action === 'process' ? 'processed' : 'packaged', ['payload' => array_filter(['method' => input('method', 300), 'product' => $name,
                    'packages' => input_num('packages') !== null ? (int) input_num('packages') : null, 'package_size' => input('package_size', 40)])]);
                if ($used !== null && $b['quantity'] !== null && abs($used - (float) $b['quantity']) < 0.0005) {
                    q("UPDATE trace_batches SET status = 'closed', updated_at = ? WHERE id = ? AND farm_id = ?", [now_utc(), $b['id'], farm_id()]);
                }
                return $new['id'];
            });
            flash('success', 'New batch created.');
            redirect('batch.php', ['id' => $newId]);
        } elseif ($action === 'recall') {
            require_can('trace.batches.create');
            $reason = input('reason', 300) ?? fail('Say why the batch is recalled.');
            $down = trace_related($id, 'down');
            tx(function () use ($id, $down, $reason, $fid) {
                foreach (array_merge([$id], $down) as $bid) {
                    q("UPDATE trace_batches SET status = 'recalled', updated_at = ? WHERE id = ? AND farm_id = ?", [now_utc(), $bid, $fid]);
                    trace_record($bid, 'status_changed', ['payload' => ['status' => 'recalled', 'reason' => $reason, 'recall_of' => $id]]);
                    q("UPDATE trace_qr_codes SET status = 'revoked', revoked_by = ?, revoked_at = ?, revoke_reason = ?, updated_at = ? WHERE batch_id = ? AND farm_id = ? AND status = 'active'",
                        [$_SESSION['uid'], now_utc(), "Recall: $reason", now_utc(), $bid, $fid]);
                }
                audit('trace.batch.recalled', null, ['type' => 'trace_batch', 'id' => $id], null, ['reason' => $reason, 'downstream' => count($down)]);
            });
            flash('warn', 'Recalled, with ' . count($down) . ' batch(es) made from it. Their QR codes now show a recall notice.');
        } elseif ($action === 'publish') {
            require_can('trace.publish');
            $fields = array_values(array_intersect((array) ($_POST['fields'] ?? []), array_keys(PUBLIC_FIELDS)));
            $fields || fail('Choose at least one field to show.');
            in_array('product', $fields, true) || array_unshift($fields, 'product');
            tx(function () use ($b, $fields, $fid) {
                $approvalId = uuid();
                insert('trace_approvals', ['id' => $approvalId, 'farm_id' => $fid, 'batch_id' => $b['id'], 'public_fields' => json_encode($fields),
                    'payload' => json_encode(public_snapshot($b, $fields), JSON_UNESCAPED_UNICODE), 'note' => input('note', 500), 'approved_by' => $_SESSION['uid'], 'approved_at' => gmdate('Y-m-d H:i:s.u')]);
                trace_record($b['id'], 'public_fields_approved', ['payload' => ['fields' => $fields]]);
                do {
                    $code = random_code(10);
                } while (val('SELECT 1 FROM trace_qr_codes WHERE code = ?', [$code]));
                insert('trace_qr_codes', ['id' => uuid(), 'farm_id' => $fid, 'batch_id' => $b['id'], 'code' => $code, 'status' => 'active', 'approval_id' => $approvalId,
                    'label' => input('label', 120), 'issued_by' => $_SESSION['uid'], 'issued_at' => now_utc(), 'scan_count' => 0, 'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
                trace_record($b['id'], 'qr_issued', ['payload' => ['code' => $code]]);
            });
            flash('success', 'Published. Print the labels from the QR codes list.');
        } elseif ($action === 'revoke') {
            require_can('trace.qr.manage');
            $qr = farm_row('trace_qr_codes', input_id('qr_id'));
            q("UPDATE trace_qr_codes SET status = 'revoked', revoked_by = ?, revoked_at = ?, revoke_reason = ?, updated_at = ? WHERE id = ? AND farm_id = ?",
                [$_SESSION['uid'], now_utc(), input('reason', 300) ?? 'Withdrawn', now_utc(), $qr['id'], $fid]);
            flash('success', 'QR code revoked.');
        }
    }, 'batch.php', ['id' => $id]);
}

$up = rows('SELECT l.link_type, l.quantity, l.unit, p.* FROM trace_batch_links l JOIN trace_batches p ON p.id = l.parent_batch_id WHERE l.farm_id = ? AND l.child_batch_id = ?', [$fid, $id]);
$down = rows('SELECT l.link_type, l.quantity, l.unit, c.* FROM trace_batch_links l JOIN trace_batches c ON c.id = l.child_batch_id WHERE l.farm_id = ? AND l.parent_batch_id = ?', [$fid, $id]);
$events = rows('SELECT e.*, u.name AS actor FROM trace_events e LEFT JOIN users u ON u.id = e.actor_user_id WHERE e.farm_id = ? AND e.batch_id = ? ORDER BY e.occurred_at, e.farm_seq', [$fid, $id]);
$codes = rows('SELECT * FROM trace_qr_codes WHERE farm_id = ? AND batch_id = ? ORDER BY issued_at DESC', [$fid, $id]);
$allDown = trace_related($id, 'down');
$customers = [];
if ($allDown || $b['kind'] === 'shipment') {
    $ids = array_merge([$id], $allDown);
    $customers = rows('SELECT DISTINCT c.name, s.code, s.status, s.delivered_at, s.dispatched_at FROM shipments s JOIN customers c ON c.id = s.customer_id
        WHERE s.farm_id = ? AND s.trace_batch_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', [$fid, ...$ids]);
}

page_start($b['batch_code']);
echo '<p><a href="' . e(url('trace.php')) . '">← Batches</a></p>';
if ($b['status'] === 'recalled') {
    echo '<div class="flash error">This batch is RECALLED.</div>';
}
$link = fn ($r) => '<a class="code" href="' . e(url('batch.php', ['id' => $r['id']])) . '">' . e($r['batch_code']) . '</a> ' . e($r['name'] ?? label($r['kind'])) . ' <span class="muted">' . e(label($r['link_type'])) . ($r['quantity'] ? ' · ' . e(qty($r['quantity'], $r['unit'])) : '') . '</span>';
echo '<div class="grid"><div class="card"><h2>' . e($b['name'] ?? label($b['kind'])) . '</h2><dl class="facts"><dt>Kind</dt><dd>' . e(label($b['kind'])) . '</dd><dt>Quantity</dt><dd>' . e(qty($b['quantity'], $b['unit'])) . '</dd>'
    . '<dt>Status</dt><dd>' . badge($b['status']) . '</dd><dt>Created</dt><dd>' . e(fdate($b['created_at'], true)) . '</dd></dl></div>'
    . '<div class="card"><h2>Came from</h2>' . ($up ? implode('<br>', array_map($link, $up)) : '<span class="muted">The start of the chain.</span>') . '<h2 style="margin-top:1rem">Went into</h2>'
    . ($down ? implode('<br>', array_map($link, $down)) : '<span class="muted">Nothing yet.</span>') . '</div></div>';
if ($customers) {
    echo '<div class="card"><h2>Customers reached</h2>';
    table($customers, ['Customer' => fn ($c) => e($c['name']), 'Shipment' => fn ($c) => e($c['code']), 'Status' => fn ($c) => badge($c['status']), 'When' => fn ($c) => e(fdate($c['delivered_at'] ?? $c['dispatched_at']))]);
    echo '</div>';
}
echo '<div class="card"><h2>History</h2><ul class="timeline">';
foreach ($events as $e) {
    $p = json_decode($e['payload'], true) ?: [];
    $details = implode(' · ', array_map(fn ($k, $v) => label($k) . ': ' . (is_array($v) ? implode(', ', array_map(fn ($x) => is_scalar($x) ? $x : '…', $v)) : $v), array_keys($p), $p));
    echo '<li><b>' . e(label($e['event_type'])) . '</b> <span class="muted">' . e(fdate($e['occurred_at'], true)) . ($e['actor'] ? ' · ' . e($e['actor']) : '') . ' · #' . e($e['farm_seq']) . '</span>'
        . ($details ? '<div class="muted">' . e(mb_substr($details, 0, 400)) . '</div>' : '') . '</li>';
}
echo '</ul></div>';

if ($codes) {
    echo '<div class="card"><h2>QR codes</h2>';
    table($codes, ['Code' => fn ($c) => '<span class="qr">' . e($c['code']) . '</span>', 'Label' => fn ($c) => e($c['label'] ?? ''), '#Scans' => fn ($c) => e($c['scan_count']),
        'Status' => fn ($c) => badge($c['status'] === 'active' ? 'active' : 'revoked') . ($c['revoke_reason'] ? ' <span class="muted">' . e($c['revoke_reason']) . '</span>' : ''),
        '' => fn ($c) => '<a href="' . e(url('labels.php', ['id' => $c['id']])) . '">Labels</a>' . ($c['status'] === 'active' && can('trace.qr.manage') ? ' ' . post_button('Revoke', ['action' => 'revoke', 'qr_id' => $c['id']], 'small danger', 'Revoke this QR code?') : '')]);
    echo '</div>';
}

if ($b['status'] === 'open') {
    echo '<div class="grid">';
    if (can('trace.batches.create')) {
        form_start('Split into parts');
        echo '<input type="hidden" name="action" value="split"><div class="fields">';
        for ($i = 0; $i < 4; $i++) {
            echo field('Part ' . ($i + 1) . ' (' . ($b['unit'] ?? 'qty') . ')', '<input name="parts[]" inputmode="decimal">');
        }
        echo '</div>';
        form_end('Split');
        form_start('Process (dry, grade, mill…)');
        echo '<input type="hidden" name="action" value="process"><div class="fields">' . field('New product', '<input name="name" required maxlength="150" placeholder="Dried maize grain">')
            . field('Quantity used (' . ($b['unit'] ?? '') . ')', '<input name="used" inputmode="decimal" value="' . e($b['quantity'] !== null ? (float) $b['quantity'] : '') . '">')
            . field('Quantity produced', '<input name="quantity" required inputmode="decimal">') . field('Unit', '<input name="unit" value="' . e($b['unit']) . '">')
            . field('Method', '<input name="method" maxlength="300" placeholder="Sun-dried to 13% moisture">') . '</div>';
        form_end('Process');
        form_start('Pack');
        echo '<input type="hidden" name="action" value="package"><div class="fields">' . field('Product', '<input name="name" required maxlength="150" placeholder="Maize grain 50 kg bags">')
            . field('Quantity used (' . ($b['unit'] ?? '') . ')', '<input name="used" inputmode="decimal">') . field('Packages', '<input name="packages" inputmode="numeric">')
            . field('Package size', '<input name="package_size" maxlength="40" placeholder="50 kg">') . field('Quantity packed', '<input name="quantity" required inputmode="decimal">')
            . field('Unit', '<input name="unit" value="' . e($b['unit']) . '">') . '</div>';
        form_end('Pack');
    }
    if (can('trace.publish')) {
        form_start('Publish with a QR code');
        echo '<input type="hidden" name="action" value="publish"><p class="muted">Choose exactly what a customer scanning the code may see. Nothing else is shown.</p><div class="stack">';
        foreach (PUBLIC_FIELDS as $k => $l) {
            echo '<label class="row"><input type="checkbox" name="fields[]" value="' . e($k) . '" style="width:auto"' . (in_array($k, ['product', 'farm', 'region', 'crop', 'dates'], true) ? ' checked' : '') . '> ' . e($l) . '</label>';
        }
        echo '</div><div class="fields">' . field('Label (for you)', '<input name="label" maxlength="120" placeholder="Bag labels, run 2">') . '</div>';
        form_end('Publish');
    }
    if (can('trace.batches.create')) {
        form_start('Recall this batch');
        echo '<input type="hidden" name="action" value="recall"><p class="muted">Marks this batch and everything made from it as recalled, and turns their QR codes into a recall notice.</p>'
            . field('Reason', '<input name="reason" required maxlength="300">');
        form_end('Recall');
    }
    echo '</div>';
}
page_end();
