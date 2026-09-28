<?php
/* Crops: cycles from planting to harvest, field reports, harvests, the farm's crop list and seasons. */
require __DIR__ . '/inc/bootstrap.php';

$farm = require_farm('crops.operations.view');
$fid = $farm['id'];
$tab = input_in('tab', ['cycles', 'observations', 'harvests', 'crops']) ?? 'cycles';

const PLANTING_METHODS = ['direct', 'transplant'];
const YIELD_UNITS = ['kg', 'bags', 'bunches', 'crates', 'tonnes', 'pieces'];

if (is_post()) {
    $action = input('action', 20);
    handle(function () use ($action, $fid) {
        if ($action === 'crop') {
            require_can('crops.plans.manage');
            $global = input_id('global_crop_id');
            $g = $global ? row('SELECT * FROM global_crops WHERE id = ?', [$global]) : null;
            $name = input('name', 120) ?? ($g['name'] ?? fail('Choose a crop or type its name.'));
            $variety = input('variety', 120);
            if (val('SELECT 1 FROM crops WHERE farm_id = ? AND name = ? AND COALESCE(variety, \'\') = ?', [$fid, $name, $variety ?? ''])) {
                fail('This crop is already on the list.');
            }
            insert('crops', ['id' => uuid(), 'farm_id' => $fid, 'global_crop_id' => $g['id'] ?? null, 'name' => $name, 'variety' => $variety,
                'maturity_days' => input_num('maturity_days'), 'yield_unit' => input_in('yield_unit', YIELD_UNITS) ?? 'kg', 'is_active' => 1,
                'created_at' => now_utc(), 'updated_at' => now_utc()]);
            flash('success', "$name added.");
        } elseif ($action === 'season') {
            require_can('crops.plans.manage');
            $from = input_date('starts_on') ?? fail('Give the start date.');
            $to = input_date('ends_on') ?? fail('Give the end date.');
            if ($to <= $from) {
                fail('The season must end after it starts.');
            }
            insert('crop_seasons', ['id' => uuid(), 'farm_id' => $fid, 'name' => input('name', 80) ?? fail('Name the season.'), 'starts_on' => $from, 'ends_on' => $to,
                'created_at' => now_utc(), 'updated_at' => now_utc()]);
            flash('success', 'Season added.');
        } elseif ($action === 'cycle') {
            require_can('crops.operations.record');
            $plot = farm_row('farm_plots', input_id('plot_id'));
            $crop = farm_row('crops', input_id('crop_id'));
            $season = input_id('season_id');
            $planted = input_date('planted_on') ?? fail('Give the planting date.');
            $area = input_num('area_ha') ?? (float) ($plot['area_ha'] ?? $plot['declared_area_ha']);
            if ($area <= 0) {
                fail('Give the area planted, in hectares.');
            }
            $open = (int) val("SELECT COUNT(*) FROM crop_cycles WHERE farm_id = ? AND plot_id = ? AND stage <> 'closed'", [$fid, $plot['id']]);
            if ($open && !(farm_settings()['allow_intercropping'] ?? false)) {
                fail("Plot {$plot['code']} already has a crop growing. Close it first, or allow intercropping in the farm settings.");
            }
            $method = input_in('planting_method', PLANTING_METHODS) ?? 'direct';
            $id = uuid();
            $cropName = $crop['name'] . ($crop['variety'] ? " ({$crop['variety']})" : '');
            tx(function () use ($id, $fid, $plot, $crop, $season, $planted, $area, $method, $cropName) {
                $lot = trace_create_batch('crop_lot', ['name' => "$cropName on {$plot['code']}", 'origin_plot_id' => $plot['id'], 'source_type' => 'crop_cycle', 'source_id' => $id], ['occurred_at' => $planted, 'plot_id' => $plot['id']]);
                $expected = $crop['maturity_days'] ? date('Y-m-d', strtotime("$planted +{$crop['maturity_days']} days")) : input_date('expected_harvest_on');
                insert('crop_cycles', ['id' => $id, 'farm_id' => $fid, 'code' => next_code('crop_cycles', 'CC'), 'season_id' => $season && belongs('crop_seasons', $season) ? $season : null,
                    'plot_id' => $plot['id'], 'crop_id' => $crop['id'], 'planting_method' => $method, 'stage' => $method === 'transplant' ? 'nursery' : 'planted',
                    'area_ha' => $area, 'planted_on' => $method === 'direct' ? $planted : null, 'sown_on' => $method === 'transplant' ? $planted : null,
                    'expected_harvest_on' => $expected, 'expected_yield' => input_num('expected_yield'), 'yield_unit' => $crop['yield_unit'],
                    'crop_lot_batch_id' => $lot['id'], 'notes' => input('notes', 2000), 'created_by' => $_SESSION['uid'], 'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
                trace_record($lot['id'], $method === 'direct' ? 'planted' : 'sown', ['occurred_at' => $planted, 'plot_id' => $plot['id'], 'subject_type' => 'crop_cycle', 'subject_id' => $id,
                    'payload' => ['crop' => $cropName, 'plot' => $plot['code'], 'area_ha' => number_format($area, 4, '.', '')]]);
                $seed = input_id('seed_batch_id');
                if ($seed && ($seedBatch = row("SELECT * FROM trace_batches WHERE id = ? AND farm_id = ? AND kind IN ('seed_lot','input_lot','nursery')", [$seed, $fid]))) {
                    trace_link($seedBatch, $lot, 'derived', null, null, ['occurred_at' => $planted]);
                    q('UPDATE crop_cycles SET seed_batch_id = ? WHERE id = ?', [$seed, $id]);
                }
            });
            audit('crops.cycle.created', null, ['type' => 'crop_cycle', 'id' => $id], null, ['plot' => $plot['code'], 'crop' => $cropName]);
            flash('success', "$cropName planted on {$plot['code']}.");
            redirect('cycle.php', ['id' => $id]);
        }
    }, 'crops.php', ['tab' => $action === 'crop' || $action === 'season' ? 'crops' : 'cycles']);
}

page_start('Crops');
tabs(['cycles' => 'Crop cycles', 'observations' => 'Field reports', 'harvests' => 'Harvests', 'crops' => 'Crops & seasons'], $tab);

if ($tab === 'cycles') {
    $show = input_in('show', ['open', 'closed']) ?? 'open';
    $cycles = rows('SELECT c.*, p.code AS plot_code, cr.name AS crop, cr.variety, s.name AS season,
        (SELECT COUNT(*) FROM crop_observations o WHERE o.cycle_id = c.id AND o.status <> \'resolved\') AS open_obs,
        (SELECT COALESCE(SUM(h.quantity), 0) FROM crop_harvests h WHERE h.cycle_id = c.id) AS harvested
        FROM crop_cycles c JOIN farm_plots p ON p.id = c.plot_id JOIN crops cr ON cr.id = c.crop_id LEFT JOIN crop_seasons s ON s.id = c.season_id
        WHERE c.farm_id = ? AND ' . ($show === 'open' ? "c.stage <> 'closed'" : "c.stage = 'closed'") . ' ORDER BY c.code DESC', [$fid]);
    echo '<p class="row"><a href="?tab=cycles&show=open"' . ($show === 'open' ? ' class="btn primary"' : ' class="btn"') . '>Growing</a><a href="?tab=cycles&show=closed"' . ($show === 'closed' ? ' class="btn primary"' : ' class="btn"') . '>Closed</a></p>';
    echo '<div class="card">';
    table($cycles, [
        'Cycle' => fn ($c) => '<a href="' . e(url('cycle.php', ['id' => $c['id']])) . '"><b>' . e($c['code']) . '</b></a>',
        'Crop' => fn ($c) => e($c['crop'] . ($c['variety'] ? " ({$c['variety']})" : '')),
        'Plot' => fn ($c) => e($c['plot_code']),
        '#Area (ha)' => fn ($c) => e(qty($c['area_ha'])),
        'Stage' => fn ($c) => badge($c['stage'] === 'closed' ? 'closed' : 'active') . ' ' . e(label($c['stage'])),
        'Planted' => fn ($c) => e(fdate($c['planted_on'] ?? $c['sown_on'])),
        'Harvest due' => fn ($c) => e(fdate($c['expected_harvest_on'])),
        'Safe to harvest' => fn ($c) => $c['safe_harvest_on'] && $c['safe_harvest_on'] > farm_today() ? '<span class="badge bad">from ' . e(fdate($c['safe_harvest_on'])) . '</span>' : '<span class="muted">yes</span>',
        '#Harvested' => fn ($c) => e(qty($c['harvested'], $c['yield_unit'])),
        'Reports' => fn ($c) => $c['open_obs'] ? '<span class="badge warn">' . e($c['open_obs']) . ' open</span>' : '',
    ], $show === 'open' ? 'Nothing growing. Plant a crop below.' : 'No closed cycles.');
    echo '</div>';

    if (can('crops.operations.record')) {
        $plots = rows('SELECT id, code, name FROM farm_plots WHERE farm_id = ? AND deleted_at IS NULL ORDER BY code', [$fid]);
        $crops = rows('SELECT id, name, variety FROM crops WHERE farm_id = ? AND is_active = 1 ORDER BY name', [$fid]);
        $seasons = rows('SELECT id, name FROM crop_seasons WHERE farm_id = ? ORDER BY starts_on DESC', [$fid]);
        $seeds = rows("SELECT id, batch_code, name, kind FROM trace_batches WHERE farm_id = ? AND kind IN ('seed_lot','input_lot','nursery') AND status = 'open' ORDER BY created_at DESC LIMIT 100", [$fid]);
        form_start('Plant a crop (new cycle)');
        echo '<input type="hidden" name="action" value="cycle"><div class="fields">'
            . field('Plot', '<select name="plot_id" required>' . options($plots, 'id', fn ($p) => $p['code'] . ' ' . $p['name']) . '</select>')
            . field('Crop', '<select name="crop_id" required>' . options($crops, 'id', fn ($c) => $c['name'] . ($c['variety'] ? " ({$c['variety']})" : '')) . '</select>', 'Add crops under "Crops & seasons".')
            . field('Season', '<select name="season_id">' . options($seasons, 'id', 'name') . '</select>')
            . field('Method', '<select name="planting_method">' . enum_options(PLANTING_METHODS, 'direct') . '</select>', 'Transplant starts in the nursery.')
            . field('Planted / sown on', '<input type="date" name="planted_on" required value="' . e(farm_today()) . '">')
            . field('Area (ha)', '<input name="area_ha" inputmode="decimal">', 'Leave empty to use the plot area.')
            . field('Expected yield', '<input name="expected_yield" inputmode="decimal">')
            . field('Seed lot', '<select name="seed_batch_id">' . options($seeds, 'id', fn ($b) => $b['batch_code'] . ' ' . ($b['name'] ?? label($b['kind']))) . '</select>', 'Links the seed to this crop for traceability.')
            . field('Notes', '<textarea name="notes"></textarea>') . '</div>';
        form_end('Plant');
    }
} elseif ($tab === 'observations') {
    $obs = rows('SELECT o.*, c.code AS cycle_code, p.code AS plot_code FROM crop_observations o JOIN crop_cycles c ON c.id = o.cycle_id JOIN farm_plots p ON p.id = c.plot_id
        WHERE o.farm_id = ? ORDER BY o.status = \'resolved\', o.observed_at DESC LIMIT 200', [$fid]);
    echo '<div class="card">';
    table($obs, [
        'When' => fn ($o) => e(fdate($o['observed_at'])),
        'Report' => fn ($o) => '<b>' . e($o['title']) . '</b><div class="muted">' . e(label($o['kind'])) . ($o['affected_pct'] ? ' · ' . e(qty($o['affected_pct'])) . '% affected' : '') . '</div>',
        'Cycle' => fn ($o) => '<a href="' . e(url('cycle.php', ['id' => $o['cycle_id']])) . '">' . e($o['cycle_code']) . '</a> · ' . e($o['plot_code']),
        'Severity' => fn ($o) => e(label($o['severity'])),
        'Status' => fn ($o) => badge($o['status']),
    ], 'No field reports.');
    echo '</div>';
} elseif ($tab === 'harvests') {
    $h = rows('SELECT h.*, c.code AS cycle_code, cr.name AS crop, b.batch_code FROM crop_harvests h JOIN crop_cycles c ON c.id = h.cycle_id JOIN crops cr ON cr.id = c.crop_id
        JOIN trace_batches b ON b.id = h.trace_batch_id WHERE h.farm_id = ? ORDER BY h.harvested_on DESC', [$fid]);
    echo '<div class="card">';
    table($h, [
        'Date' => fn ($r) => e(fdate($r['harvested_on'])),
        'Crop' => fn ($r) => e($r['crop']) . ' · <a href="' . e(url('cycle.php', ['id' => $r['cycle_id']])) . '">' . e($r['cycle_code']) . '</a>',
        '#Quantity' => fn ($r) => e(qty($r['quantity'], $r['unit'])),
        'Grade' => fn ($r) => e($r['quality_grade'] ?? '—'),
        '#Moisture' => fn ($r) => $r['moisture_pct'] !== null ? e(qty($r['moisture_pct'])) . '%' : '—',
        'Batch' => fn ($r) => '<a class="code" href="' . e(url('batch.php', ['id' => $r['trace_batch_id']])) . '">' . e($r['batch_code']) . '</a>',
    ], 'No harvests yet.');
    echo '</div>';
} else {
    $crops = rows('SELECT c.*, (SELECT COUNT(*) FROM crop_cycles y WHERE y.crop_id = c.id) AS cycles FROM crops c WHERE c.farm_id = ? ORDER BY c.name', [$fid]);
    $seasons = rows('SELECT * FROM crop_seasons WHERE farm_id = ? ORDER BY starts_on DESC', [$fid]);
    echo '<div class="grid"><div class="card"><h2>Crops grown here</h2>';
    table($crops, ['Crop' => fn ($c) => e($c['name']), 'Variety' => fn ($c) => e($c['variety'] ?? '—'), '#Days to maturity' => fn ($c) => e($c['maturity_days'] ?? '—'),
        'Unit' => fn ($c) => e($c['yield_unit']), '#Cycles' => fn ($c) => e($c['cycles'])]);
    echo '</div><div class="card"><h2>Seasons</h2>';
    table($seasons, ['Season' => fn ($s) => e($s['name']), 'From' => fn ($s) => e(fdate($s['starts_on'])), 'To' => fn ($s) => e(fdate($s['ends_on']))]);
    echo '</div></div>';
    if (can('crops.plans.manage')) {
        $global = rows('SELECT id, name, category FROM global_crops WHERE is_active = 1 ORDER BY name');
        echo '<div class="grid">';
        form_start('Add a crop');
        echo '<input type="hidden" name="action" value="crop"><div class="fields">'
            . field('From the catalogue', '<select name="global_crop_id">' . options($global, 'id', fn ($g) => $g['name'] . ' (' . $g['category'] . ')') . '</select>')
            . field('Or name', '<input name="name" maxlength="120">') . field('Variety', '<input name="variety" maxlength="120">')
            . field('Days to maturity', '<input name="maturity_days" inputmode="numeric">') . field('Harvest unit', '<select name="yield_unit">' . enum_options(YIELD_UNITS, 'kg') . '</select>') . '</div>';
        form_end('Add crop');
        form_start('Add a season');
        echo '<input type="hidden" name="action" value="season"><div class="fields">' . field('Name', '<input name="name" required placeholder="2027 first rains">')
            . field('Starts', '<input type="date" name="starts_on" required>') . field('Ends', '<input type="date" name="ends_on" required>') . '</div>';
        form_end('Add season');
        echo '</div>';
    }
}
page_end();
