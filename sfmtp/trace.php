<?php
/* Traceability: every batch, the QR codes, and the tamper check of the event chain. */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/publish.php';

$farm = require_farm('trace.batches.view');
$fid = $farm['id'];
$tab = input_in('tab', ['batches', 'qr', 'integrity']) ?? 'batches';

page_start('Traceability');
tabs(['batches' => 'Batches', 'qr' => 'QR codes', 'integrity' => 'Tamper check'], $tab);

if ($tab === 'batches') {
    $kind = input_in('kind', BATCH_KINDS);
    $status = input_in('status', ['open', 'closed', 'recalled']);
    $search = input('q', 30);
    $where = 'farm_id = ?';
    $params = [$fid];
    if ($kind) {
        $where .= ' AND kind = ?';
        $params[] = $kind;
    }
    if ($status) {
        $where .= ' AND status = ?';
        $params[] = $status;
    }
    if ($search) {
        $where .= ' AND (batch_code LIKE ? OR name LIKE ?)';
        array_push($params, "%$search%", "%$search%");
    }
    $list = rows("SELECT * FROM trace_batches WHERE $where ORDER BY created_at DESC LIMIT 300", $params);
    echo '<form class="row no-print" method="get"><input name="q" placeholder="Batch code or name" value="' . e($search) . '" style="max-width:220px"><select name="kind" style="max-width:170px">' . enum_options(BATCH_KINDS, $kind, true) . '</select>'
        . '<select name="status" style="max-width:140px">' . enum_options(['open', 'closed', 'recalled'], $status, true) . '</select><button>Filter</button></form><br><div class="card">';
    table($list, ['Batch' => fn ($b) => '<a class="code" href="' . e(url('batch.php', ['id' => $b['id']])) . '"><b>' . e($b['batch_code']) . '</b></a>', 'What' => fn ($b) => e($b['name'] ?? '—'),
        'Kind' => fn ($b) => e(label($b['kind'])), '#Quantity' => fn ($b) => e(qty($b['quantity'], $b['unit'])), 'Created' => fn ($b) => e(fdate($b['created_at'])), 'Status' => fn ($b) => badge($b['status'])], 'No batches.');
    echo '</div>';
} elseif ($tab === 'qr') {
    $codes = rows('SELECT q.*, b.batch_code, b.name FROM trace_qr_codes q JOIN trace_batches b ON b.id = q.batch_id WHERE q.farm_id = ? ORDER BY q.issued_at DESC', [$fid]);
    echo '<div class="card">';
    table($codes, ['Code' => fn ($c) => '<span class="qr">' . e($c['code']) . '</span>', 'Batch' => fn ($c) => '<a class="code" href="' . e(url('batch.php', ['id' => $c['batch_id']])) . '">' . e($c['batch_code']) . '</a> ' . e($c['name']),
        'Label' => fn ($c) => e($c['label'] ?? ''), '#Scans' => fn ($c) => e($c['scan_count']), 'Last scan' => fn ($c) => e(fdate($c['last_scanned_at'])),
        'Status' => fn ($c) => badge($c['status'] === 'active' ? 'active' : 'revoked'), '' => fn ($c) => '<a href="' . e(url('labels.php', ['id' => $c['id']])) . '">Labels</a> · <a href="' . e(qr_url($c['code'])) . '" target="_blank" rel="noopener">Public page</a>'], 'No QR codes yet. Publish a batch from its page.');
    echo '</div>';
} else {
    [$ok, $n, $bad] = trace_verify();
    echo '<div class="card"><h2>Event chain</h2><p>Every traceability event is linked to the one before it by a SHA-256 fingerprint. Changing any past event breaks the chain from that point.</p>';
    echo $ok ? '<p>' . badge('active') . ' Intact: all ' . e($n) . ' events check out.</p>' : '<p class="flash error">Broken at event number ' . e($bad) . ': an event was altered after it was written. Tell the platform team.</p>';
    audit('trace.chain_verified', null, null, null, ['ok' => $ok, 'events' => $n]);
    echo '</div>';
}
page_end();
