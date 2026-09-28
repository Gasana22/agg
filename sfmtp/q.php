<?php
/* The public page behind a QR code. No sign-in; shows only the approved snapshot. */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/publish.php';

$code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['c'] ?? '')));

// 60 scans a minute per address (the cache table holds the counters).
$key = 'qr:' . hash('sha256', client_ip()) . ':' . intdiv(time(), 60);
q('INSERT INTO cache (`key`, value, expiration) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE value = value + 1', [$key, time() + 120]);
if ((int) val('SELECT value FROM cache WHERE `key` = ?', [$key]) > 60) {
    http_response_code(429);
    exit('Too many scans. Wait a minute and try again.');
}
if (random_int(1, 50) === 1) {
    q('DELETE FROM cache WHERE `key` LIKE ? AND expiration < ?', ['qr:%', time()]);
}

$qr = strlen($code) >= 6 && strlen($code) <= 16 ? row('SELECT q.*, a.payload, f.name AS farm_name, f.status AS farm_status FROM trace_qr_codes q JOIN trace_approvals a ON a.id = q.approval_id JOIN farms f ON f.id = q.farm_id WHERE q.code = ?', [$code]) : null;

echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Product traceability</title><link rel="stylesheet" href="' . e(url('assets/style.css')) . '"></head><body><main class="public">';
echo '<p><b style="color:var(--primary)">🌱 ' . e(config('app_name', 'SFMTP')) . ' traceability</b></p>';

if (!$qr || in_array($qr['farm_status'], ['closed'], true)) {
    http_response_code(404);
    echo '<div class="card"><h2>Unknown code</h2><p>This code was not found. Check the code on the label.</p></div>';
} elseif ($qr['status'] !== 'active') {
    echo '<div class="card" style="border-color:var(--bad)"><h2 style="color:var(--bad)">⚠ Recall notice</h2><p>This product has been withdrawn by the producer.</p><p><b>' . e($qr['revoke_reason'] ?? '') . '</b></p><p class="muted">Do not use it. Contact the seller or ' . e($qr['farm_name']) . '.</p></div>';
} else {
    // Count the scan (anonymous: a daily number per code).
    q('UPDATE trace_qr_codes SET scan_count = scan_count + 1, last_scanned_at = ? WHERE id = ?', [now_utc(), $qr['id']]);
    q('INSERT INTO trace_qr_scans (farm_id, qr_code_id, day, country, scans) VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE scans = scans + 1', [$qr['farm_id'], $qr['id'], gmdate('Y-m-d'), 'ZZ']);
    $p = json_decode($qr['payload'], true) ?: [];
    echo '<div class="card"><div class="muted">' . e(strtoupper($p['product']['kind'] ?? '')) . '</div><h1 style="margin:.2rem 0">' . e($p['product']['name'] ?? 'Product') . '</h1>';
    if (!empty($p['farm'])) {
        echo '<div>📍 ' . e($p['farm']) . (!empty($p['region']) ? ', ' . e(trim(($p['region']['district'] ?? '') . ', ' . ($p['region']['country'] ?? ''), ', ')) : '') . '</div>';
    }
    echo '<div class="muted" style="margin-top:.4rem">Code <span class="code">' . e($qr['code']) . '</span>' . (!empty($p['batch_code']) ? ' · Batch <span class="code">' . e($p['batch_code']) . '</span>' : '') . ' · <span style="color:var(--primary)">✔ Verified by the producer</span></div></div>';
    echo render_snapshot($p);
}
echo '<p class="muted" style="text-align:center">Scans are counted anonymously.</p></main></body></html>';
