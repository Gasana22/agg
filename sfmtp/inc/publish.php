<?php
/* What a QR scan shows: a snapshot of a batch's approved public fields. */

const PUBLIC_FIELDS = [
    'product' => 'Product name and kind',
    'batch_code' => 'Batch code',
    'farm' => 'Farm name',
    'region' => 'District and country',
    'origin' => 'Field (plot) of origin',
    'crop' => 'Crop and variety',
    'dates' => 'Key dates (planted, harvested, processed, packed)',
    'seed_source' => 'Seed source',
    'inputs' => 'Fertilizers and sprays applied, with withholding periods',
    'processing' => 'Processing and packing steps',
    'journey' => 'The batch journey (codes and dates)',
];

/** Build the full public snapshot of a batch; only the approved fields are kept. */
function public_snapshot(array $batch, array $fields): array
{
    $fid = farm_id();
    $farm = current_farm();
    $up = trace_related($batch['id'], 'up');
    $all = array_merge([$batch['id']], $up);
    $in = implode(',', array_fill(0, count($all), '?'));
    $batches = rows("SELECT * FROM trace_batches WHERE farm_id = ? AND id IN ($in) ORDER BY created_at", [$fid, ...$all]);

    $snap = [];
    $snap['product'] = ['kind' => label($batch['kind']), 'name' => $batch['name']];
    $snap['batch_code'] = $batch['batch_code'];
    $snap['farm'] = $farm['name'];
    $snap['region'] = ['country' => $farm['country'], 'district' => $farm['district']];
    $plots = array_filter(array_unique(array_column($batches, 'origin_plot_id')));
    $snap['origin'] = $plots ? array_column(rows('SELECT code FROM farm_plots WHERE id IN (' . implode(',', array_fill(0, count($plots), '?')) . ')', array_values($plots)), 'code') : [];
    $snap['crop'] = array_values(array_unique(array_map(fn ($c) => $c['name'] . ($c['variety'] ? " ({$c['variety']})" : ''),
        rows("SELECT cr.name, cr.variety FROM crop_cycles c JOIN crops cr ON cr.id = c.crop_id WHERE c.farm_id = ? AND c.crop_lot_batch_id IN ($in)", [$fid, ...$all]))));

    $events = rows("SELECT event_type, occurred_at, payload, batch_id FROM trace_events WHERE farm_id = ? AND batch_id IN ($in) ORDER BY occurred_at", [$fid, ...$all]);
    $dates = [];
    foreach ($events as $e) {
        $d = substr($e['occurred_at'], 0, 10);
        $key = ['planted' => 'planted', 'sown' => 'planted', 'harvested' => 'harvested'][$e['event_type']] ?? null;
        if ($key && !isset($dates[$key])) {
            $dates[$key] = $d;
        }
    }
    foreach ($batches as $b) {
        if ($b['kind'] === 'processed') {
            $dates['processed'] = substr($b['created_at'], 0, 10);
        }
        if ($b['kind'] === 'packaged') {
            $dates['packed'] = substr($b['created_at'], 0, 10);
        }
    }
    $snap['dates'] = $dates;
    $snap['seed_source'] = array_values(array_map(fn ($b) => ['name' => $b['name'] ?? $b['batch_code']], array_filter($batches, fn ($b) => in_array($b['kind'], ['seed_lot', 'nursery'], true))));
    $snap['inputs'] = [];
    foreach ($events as $e) {
        if ($e['event_type'] === 'input_applied') {
            $p = json_decode($e['payload'], true) ?: [];
            $snap['inputs'][] = array_filter(['date' => substr($e['occurred_at'], 0, 10), 'type' => 'input applied', 'product' => $p['product'] ?? null,
                'withholding_days' => $p['withholding_days'] ?? null], fn ($v) => $v !== null);
        }
    }
    $snap['processing'] = [];
    foreach ($batches as $b) {
        if (in_array($b['kind'], ['processed', 'packaged'], true)) {
            $snap['processing'][] = array_filter(['date' => substr($b['created_at'], 0, 10), 'step' => $b['kind'] === 'processed' ? 'Processed' : 'Packed', 'product' => $b['name']]);
        }
    }
    $snap['journey'] = array_values(array_map(fn ($b) => ['date' => substr($b['created_at'], 0, 10), 'kind' => label($b['kind']), 'batch_code' => $b['batch_code']],
        array_filter($batches, fn ($b) => $b['kind'] !== 'shipment')));

    return array_intersect_key($snap, array_flip($fields));
}

/** Render a snapshot for the public page (only what was approved). */
function render_snapshot(array $p): string
{
    $h = '';
    $facts = [];
    if (!empty($p['crop'])) {
        $facts['Crop'] = implode(', ', $p['crop']);
    }
    if (!empty($p['origin'])) {
        $facts['Field'] = implode(', ', $p['origin']);
    }
    foreach (['planted' => 'Planted', 'harvested' => 'Harvested', 'processed' => 'Processed', 'packed' => 'Packed'] as $k => $l) {
        if (!empty($p['dates'][$k])) {
            $facts[$l] = date('j M Y', strtotime($p['dates'][$k]));
        }
    }
    if ($facts) {
        $h .= '<div class="card"><h2>🌱 Grown</h2><dl class="facts">';
        foreach ($facts as $k => $v) {
            $h .= '<dt>' . e($k) . '</dt><dd>' . e($v) . '</dd>';
        }
        $h .= '</dl></div>';
    }
    if (!empty($p['seed_source'])) {
        $h .= '<div class="card"><h2>Seed</h2>' . implode('<br>', array_map(fn ($s) => e($s['name']), $p['seed_source'])) . '</div>';
    }
    if (!empty($p['inputs'])) {
        $h .= '<div class="card"><h2>Treatments and inputs</h2><table>';
        foreach ($p['inputs'] as $i) {
            $h .= '<tr><td>' . e($i['product'] ?? '') . (isset($i['withholding_days']) ? ' <span class="muted">· ' . e($i['withholding_days']) . '-day withholding respected</span>' : '') . '</td><td class="num">' . e(date('j M Y', strtotime($i['date']))) . '</td></tr>';
        }
        $h .= '</table></div>';
    }
    if (!empty($p['processing'])) {
        $h .= '<div class="card"><h2>Processing</h2><ul class="timeline">';
        foreach ($p['processing'] as $s) {
            $h .= '<li><b>' . e($s['step'] ?? '') . '</b> ' . e($s['product'] ?? '') . ' <span class="muted">' . e(date('j M Y', strtotime($s['date']))) . '</span>' . (!empty($s['method']) ? '<div>' . e($s['method']) . '</div>' : '') . '</li>';
        }
        $h .= '</ul></div>';
    }
    if (!empty($p['journey'])) {
        $h .= '<div class="card"><h2>Journey</h2><ul class="timeline">';
        foreach ($p['journey'] as $j) {
            $h .= '<li>' . e($j['kind']) . ' <span class="code">' . e($j['batch_code']) . '</span> <span class="muted">' . e(date('j M Y', strtotime($j['date']))) . '</span></li>';
        }
        $h .= '</ul></div>';
    }
    return $h;
}

function qr_url(string $code): string
{
    return rtrim((string) config('app_url'), '/') . '/q.php?c=' . rawurlencode($code);
}
