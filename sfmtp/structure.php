<?php
/* Farm structure: blocks → sections → plots, and locations (stores, houses, water…). */
require __DIR__ . '/inc/bootstrap.php';

$farm = require_farm('structure.view');
$fid = $farm['id'];
$tab = input_in('tab', ['plots', 'locations']) ?? 'plots';

const LAND_USES = ['crop', 'pasture', 'fallow', 'orchard', 'forestry', 'other'];
const IRRIGATION = ['rainfed', 'drip', 'sprinkler', 'furrow', 'flood', 'other'];
const LOCATION_KINDS = ['store', 'building', 'paddock', 'housing', 'water', 'gate', 'office', 'other'];

if (is_post()) {
    require_can('structure.manage');
    $action = input('action', 20);
    handle(function () use ($action, $fid) {
        $code = strtoupper((string) input('code', 30));
        $name = input('name', 120) ?? fail('A name is required.');
        if ($code === '' || !preg_match('/^[A-Z0-9-]{1,30}$/', $code)) {
            fail('The code uses letters, digits and dashes, e.g. B-3.');
        }
        $area = input_num('area_ha');
        $common = ['id' => uuid(), 'farm_id' => $fid, 'code' => $code, 'name' => $name, 'description' => input('description', 1000),
            'declared_area_ha' => $area, 'created_by' => $_SESSION['uid'], 'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()];
        $table = ['block' => 'farm_blocks', 'section' => 'farm_sections', 'plot' => 'farm_plots', 'location' => 'farm_locations'][$action] ?? fail('Unknown action.');
        if (val("SELECT 1 FROM $table WHERE farm_id = ? AND code = ? AND deleted_at IS NULL", [$fid, $code])) {
            fail("The code $code is already used.");
        }
        if ($action === 'section') {
            $common['block_id'] = belongs('farm_blocks', input_id('block_id')) ? input_id('block_id') : fail('Choose the block.');
        } elseif ($action === 'plot') {
            $section = input_id('section_id');
            $common['section_id'] = $section && belongs('farm_sections', $section) ? $section : null;
            $common['land_use'] = input_in('land_use', LAND_USES) ?? 'crop';
            $common['irrigation'] = input_in('irrigation', IRRIGATION) ?? 'rainfed';
        } elseif ($action === 'location') {
            unset($common['declared_area_ha']);
            $common['kind'] = input_in('kind', LOCATION_KINDS) ?? fail('Choose what kind of place it is.');
            $plot = input_id('plot_id');
            $common['plot_id'] = $plot && belongs('farm_plots', $plot) ? $plot : null;
            $lat = input_num('latitude');
            $lng = input_num('longitude');
            if (($lat !== null && ($lat < -90 || $lat > 90)) || ($lng !== null && ($lng < -180 || $lng > 180))) {
                fail('The coordinates are out of range.');
            }
            $common['latitude'] = $lat;
            $common['longitude'] = $lng;
        }
        insert($table, $common);
        audit("structure.$action.created", null, ['type' => $action, 'id' => $common['id']], null, ['code' => $code, 'name' => $name]);
        flash('success', label($action) . " $code added.");
    }, 'structure.php', ['tab' => $action === 'location' ? 'locations' : 'plots']);
}

page_start('Farm map');
tabs(['plots' => 'Blocks, sections and plots', 'locations' => 'Locations'], $tab);

if ($tab === 'plots') {
    $blocks = rows('SELECT * FROM farm_blocks WHERE farm_id = ? AND deleted_at IS NULL ORDER BY code', [$fid]);
    $sections = rows('SELECT s.*, b.code AS block_code FROM farm_sections s JOIN farm_blocks b ON b.id = s.block_id WHERE s.farm_id = ? AND s.deleted_at IS NULL ORDER BY s.code', [$fid]);
    $plots = rows('SELECT p.*, s.code AS section_code, (SELECT COUNT(*) FROM crop_cycles c WHERE c.plot_id = p.id AND c.stage <> \'closed\') AS open_cycles
        FROM farm_plots p LEFT JOIN farm_sections s ON s.id = p.section_id WHERE p.farm_id = ? AND p.deleted_at IS NULL ORDER BY p.code', [$fid]);
    $totalArea = array_sum(array_map(fn ($p) => (float) ($p['area_ha'] ?? $p['declared_area_ha']), $plots));
    echo '<div class="kpis">' . kpi('Blocks', (string) count($blocks)) . kpi('Sections', (string) count($sections)) . kpi('Plots', (string) count($plots))
        . kpi('Plot area', qty($totalArea, 'ha'), 'Farm size ' . qty($farm['size_ha'], 'ha')) . '</div>';

    echo '<div class="card"><h2>Plots</h2>';
    table($plots, [
        'Code' => fn ($p) => '<b>' . e($p['code']) . '</b>',
        'Name' => fn ($p) => e($p['name']),
        'Section' => fn ($p) => e($p['section_code'] ?? '—'),
        'Use' => fn ($p) => e(label($p['land_use'])) . ' · ' . e(label($p['irrigation'])),
        '#Area (ha)' => fn ($p) => e(qty($p['area_ha'] ?? $p['declared_area_ha'])),
        'Crops now' => fn ($p) => $p['open_cycles'] ? badge('active') . ' ' . e($p['open_cycles']) : '<span class="muted">—</span>',
        'Soil' => fn ($p) => $p['soil_profile'] ? e(implode(', ', array_map(fn ($k, $v) => "$k " . (is_scalar($v) ? $v : ''), array_keys(array_slice(json_decode($p['soil_profile'], true) ?: [], 0, 3)), array_slice(json_decode($p['soil_profile'], true) ?: [], 0, 3)))) : '<span class="muted">—</span>',
    ], 'No plots yet.');
    echo '</div><div class="grid"><div class="card"><h2>Blocks</h2>';
    table($blocks, ['Code' => fn ($b) => e($b['code']), 'Name' => fn ($b) => e($b['name']), '#Area (ha)' => fn ($b) => e(qty($b['area_ha'] ?? $b['declared_area_ha']))]);
    echo '</div><div class="card"><h2>Sections</h2>';
    table($sections, ['Code' => fn ($s) => e($s['code']), 'Name' => fn ($s) => e($s['name']), 'Block' => fn ($s) => e($s['block_code'])]);
    echo '</div></div>';

    if (can('structure.manage')) {
        echo '<div class="grid">';
        form_start('Add a block');
        echo '<input type="hidden" name="action" value="block"><div class="fields">' . field('Code', '<input name="code" required placeholder="B">') . field('Name', '<input name="name" required>')
            . field('Area (ha)', '<input name="area_ha" inputmode="decimal">') . '</div>';
        form_end('Add block');
        form_start('Add a section');
        echo '<input type="hidden" name="action" value="section"><div class="fields">' . field('Block', '<select name="block_id" required>' . options($blocks, 'id', fn ($b) => $b['code'] . ' ' . $b['name']) . '</select>')
            . field('Code', '<input name="code" required placeholder="B-S1">') . field('Name', '<input name="name" required>') . field('Area (ha)', '<input name="area_ha" inputmode="decimal">') . '</div>';
        form_end('Add section');
        form_start('Add a plot');
        echo '<input type="hidden" name="action" value="plot"><div class="fields">' . field('Code', '<input name="code" required placeholder="B-4">') . field('Name', '<input name="name" required>')
            . field('Section', '<select name="section_id">' . options($sections, 'id', fn ($s) => $s['code'] . ' ' . $s['name']) . '</select>')
            . field('Land use', '<select name="land_use">' . enum_options(LAND_USES, 'crop') . '</select>')
            . field('Irrigation', '<select name="irrigation">' . enum_options(IRRIGATION, 'rainfed') . '</select>')
            . field('Area (ha)', '<input name="area_ha" inputmode="decimal">') . '</div>';
        form_end('Add plot');
        echo '</div>';
    }
} else {
    $locations = rows('SELECT l.*, p.code AS plot_code FROM farm_locations l LEFT JOIN farm_plots p ON p.id = l.plot_id WHERE l.farm_id = ? AND l.deleted_at IS NULL ORDER BY l.code', [$fid]);
    echo '<div class="card">';
    table($locations, [
        'Code' => fn ($l) => '<b>' . e($l['code']) . '</b>',
        'Name' => fn ($l) => e($l['name']),
        'Kind' => fn ($l) => e(label($l['kind'])),
        'Plot' => fn ($l) => e($l['plot_code'] ?? '—'),
        'Position' => fn ($l) => $l['latitude'] !== null ? '<a href="https://www.openstreetmap.org/?mlat=' . e($l['latitude']) . '&mlon=' . e($l['longitude']) . '#map=17/' . e($l['latitude']) . '/' . e($l['longitude']) . '" target="_blank" rel="noopener">' . e(round((float) $l['latitude'], 5) . ', ' . round((float) $l['longitude'], 5)) . '</a>' : '—',
    ], 'No locations yet.');
    echo '</div>';
    if (can('structure.manage')) {
        $plots = rows('SELECT id, code, name FROM farm_plots WHERE farm_id = ? AND deleted_at IS NULL ORDER BY code', [$fid]);
        form_start('Add a location');
        echo '<input type="hidden" name="action" value="location"><div class="fields">' . field('Code', '<input name="code" required placeholder="STORE-2">') . field('Name', '<input name="name" required>')
            . field('Kind', '<select name="kind" required>' . enum_options(LOCATION_KINDS, null, true) . '</select>')
            . field('On plot', '<select name="plot_id">' . options($plots, 'id', fn ($p) => $p['code'] . ' ' . $p['name']) . '</select>')
            . field('Latitude', '<input name="latitude" inputmode="decimal" placeholder="0.4046">') . field('Longitude', '<input name="longitude" inputmode="decimal" placeholder="32.3864">') . '</div>';
        form_end('Add location');
    }
}
page_end();
