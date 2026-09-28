<?php
/* Printable QR labels for one code (A4, 24 per sheet). */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/publish.php';
require __DIR__ . '/inc/qr.php';

$farm = require_farm('trace.qr.manage');
$qr = farm_row('trace_qr_codes', input_id('id'));
$batch = row('SELECT * FROM trace_batches WHERE id = ?', [$qr['batch_id']]);
$count = max(1, min(240, (int) (input_num('count') ?? 24)));
$svg = Qr::svg(qr_url($qr['code']), 3);

page_start('Labels · ' . $qr['code']);
echo '<form class="row no-print" method="get"><input type="hidden" name="id" value="' . e($qr['id']) . '"><label>Labels <input name="count" value="' . e($count) . '" style="max-width:90px" inputmode="numeric"></label><button>Update</button><button type="button" data-print>Print</button>'
    . ' <span class="muted">Scan link: ' . e(qr_url($qr['code'])) . '</span></form>';
if ($qr['status'] !== 'active') {
    echo '<div class="flash error">This code is revoked: its page shows a recall notice.</div>';
}
echo '<style>.sheet{display:grid;grid-template-columns:repeat(3,1fr);gap:4mm;margin-top:1rem}.label{border:1px dashed #ccc;padding:3mm;display:flex;gap:3mm;align-items:center;font-size:11px;break-inside:avoid;background:#fff}.label svg{width:28mm;height:28mm;flex-shrink:0}@media print{.label{border:0}}</style><div class="sheet">';
for ($i = 0; $i < $count; $i++) {
    echo '<div class="label">' . $svg . '<div><b>' . e($batch['name'] ?? label($batch['kind'])) . '</b><br>' . e($farm['name']) . '<br><span class="code">' . e($qr['code']) . '</span><br>Scan to trace</div></div>';
}
echo '</div>';
page_end();
