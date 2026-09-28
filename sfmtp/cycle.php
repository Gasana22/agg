<?php
/* One crop cycle: field work with inputs, field reports, stage, harvests. Each step is written to the crop lot's history. */
require __DIR__ . '/inc/bootstrap.php';

$farm = require_farm('crops.operations.view');
$fid = $farm['id'];
$cycle = farm_row('crop_cycles', input_id('id'));
$id = $cycle['id'];

const OPERATION_TYPES = ['land_preparation', 'planting', 'weeding', 'fertilizing', 'spraying', 'irrigation', 'scouting', 'pruning', 'thinning', 'other'];
const OBSERVATION_KINDS = ['pest', 'disease', 'weed', 'nutrient', 'water', 'growth', 'weather', 'other'];
const SEVERITIES = ['low', 'medium', 'high', 'critical'];
const STAGES = ['nursery', 'planted', 'growing', 'harvesting', 'closed'];

if (is_post()) {
    $action = input('action', 20);
    handle(function () use ($action, $cycle, $fid, $id) {
        if ($cycle['stage'] === 'closed' && $action !== 'resolve') {
            fail('This cycle is closed.');
        }
        $lot = $cycle['crop_lot_batch_id'];
        if ($lot === null) {
            // Older cycles may have no crop lot yet; open one on first use so every step has a history.
            $lot = tx(function () use ($cycle, $fid, $id) {
                $names = row('SELECT c.name AS crop, p.code AS plot FROM crops c JOIN farm_plots p ON p.id = ? WHERE c.id = ?', [$cycle['plot_id'], $cycle['crop_id']]);
                $batch = trace_create_batch('crop_lot', ['name' => ($names['crop'] ?? 'Crop') . ' on ' . ($names['plot'] ?? ''), 'origin_plot_id' => $cycle['plot_id'],
                    'source_type' => 'crop_cycle', 'source_id' => $id], ['plot_id' => $cycle['plot_id']]);
                q('UPDATE crop_cycles SET crop_lot_batch_id = ?, updated_at = ? WHERE id = ? AND farm_id = ?', [$batch['id'], now_utc(), $id, $fid]);
                return $batch['id'];
            });
        }
        if ($action === 'operation') {
            require_can('crops.operations.record');
            $type = input_in('type', OPERATION_TYPES) ?? fail('Choose the kind of work.');
            $date = input_date('occurred_on') ?? fail('Give the date.');
            if ($date > farm_today()) {
                fail('Field work cannot be in the future.');
            }
            $opId = uuid();
            tx(function () use ($opId, $fid, $id, $type, $date, $lot, $cycle) {
                insert('crop_operations', ['id' => $opId, 'farm_id' => $fid, 'cycle_id' => $id, 'type' => $type, 'occurred_at' => "$date 08:00:00", 'status' => 'recorded',
                    'notes' => input('notes', 2000), 'labour_hours' => input_num('labour_hours'), 'cost_amount' => input_num('cost_amount'),
                    'recorded_by' => $_SESSION['uid'], 'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
                trace_record($lot, 'operation', ['occurred_at' => $date, 'plot_id' => $cycle['plot_id'], 'subject_type' => 'crop_operation', 'subject_id' => $opId,
                    'payload' => array_filter(['operation' => $type, 'notes' => input('notes', 2000), 'labour_hours' => input_num('labour_hours') !== null ? number_format(input_num('labour_hours'), 2, '.', '') : null])]);
                $product = input('product', 150);
                if ($product !== null) {
                    $qty = input_num('quantity') ?? fail('Give the quantity of the input used.');
                    $unit = input('unit', 20) ?? fail('Give the unit of the input.');
                    $wh = input_num('withholding_days');
                    insert('crop_operation_inputs', ['id' => uuid(), 'farm_id' => $fid, 'operation_id' => $opId, 'product_name' => $product, 'quantity' => $qty, 'unit' => $unit,
                        'withholding_days' => $wh, 'created_at' => now_utc()]);
                    trace_record($lot, 'input_applied', ['occurred_at' => $date, 'plot_id' => $cycle['plot_id'], 'subject_type' => 'crop_operation', 'subject_id' => $opId,
                        'payload' => array_filter(['product' => $product, 'quantity' => number_format($qty, 3, '.', ''), 'unit' => $unit, 'withholding_days' => $wh !== null ? (int) $wh : null], fn ($v) => $v !== null)]);
                    if ($wh) {
                        $safe = date('Y-m-d', strtotime("$date +" . (int) $wh . ' days'));
                        if (!$cycle['safe_harvest_on'] || $safe > $cycle['safe_harvest_on']) {
                            q('UPDATE crop_cycles SET safe_harvest_on = ?, updated_at = ? WHERE id = ? AND farm_id = ?', [$safe, now_utc(), $id, $fid]);
                        }
                    }
                }
            });
            flash('success', label($type) . ' recorded.');
        } elseif ($action === 'observation') {
            require_can('crops.operations.record');
            $kind = input_in('kind', OBSERVATION_KINDS) ?? fail('Choose what you saw.');
            $sev = input_in('severity', SEVERITIES) ?? 'low';
            $title = input('title', 150) ?? fail('Give the report a short title.');
            $pct = input_num('affected_pct');
            $obsId = uuid();
            tx(function () use ($obsId, $fid, $id, $kind, $sev, $title, $pct, $lot, $cycle) {
                insert('crop_observations', ['id' => $obsId, 'farm_id' => $fid, 'cycle_id' => $id, 'kind' => $kind, 'severity' => $sev, 'title' => $title,
                    'description' => input('description', 2000), 'affected_pct' => $pct, 'observed_at' => gmdate('Y-m-d H:i:s'), 'status' => 'open',
                    'recorded_by' => $_SESSION['uid'], 'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
                trace_record($lot, 'observation', ['plot_id' => $cycle['plot_id'], 'subject_type' => 'crop_observation', 'subject_id' => $obsId,
                    'payload' => array_filter(['kind' => $kind, 'title' => $title, 'severity' => $sev, 'affected_pct' => $pct !== null ? number_format($pct, 2, '.', '') : null])]);
                if (in_array($sev, ['high', 'critical'], true)) {
                    $owners = array_column(rows('SELECT user_id FROM farm_users WHERE farm_id = ? AND is_owner = 1', [$fid]), 'user_id');
                    notify($owners, 'pest_report', "$title on {$cycle['code']}", label($sev) . ' ' . $kind . ' report', url('cycle.php', ['id' => $id]));
                }
            });
            flash('success', 'Report saved.');
        } elseif ($action === 'resolve') {
            require_can('crops.operations.record');
            $o = farm_row('crop_observations', input_id('observation_id'));
            q("UPDATE crop_observations SET status = 'resolved', resolved_at = ?, resolution_note = ?, updated_at = ?, version = version + 1 WHERE id = ? AND farm_id = ?",
                [now_utc(), input('note', 1000), now_utc(), $o['id'], $fid]);
            trace_record($lot, 'observation_resolved', ['subject_type' => 'crop_observation', 'subject_id' => $o['id'], 'payload' => array_filter(['title' => $o['title'], 'note' => input('note', 1000)])]);
            flash('success', 'Marked as resolved.');
        } elseif ($action === 'stage') {
            require_can('crops.operations.record');
            $to = input_in('stage', STAGES) ?? fail('Choose the stage.');
            if (array_search($to, STAGES, true) <= array_search($cycle['stage'], STAGES, true)) {
                fail('A cycle only moves forward.');
            }
            $reason = $to === 'closed' ? (input_in('close_reason', ['harvested', 'failed', 'abandoned', 'other']) ?? 'harvested') : null;
            tx(function () use ($to, $reason, $cycle, $lot, $fid, $id) {
                q('UPDATE crop_cycles SET stage = ?, closed_on = ?, close_reason = ?, planted_on = COALESCE(planted_on, ?), updated_at = ?, version = version + 1 WHERE id = ? AND farm_id = ?',
                    [$to, $to === 'closed' ? farm_today() : null, $reason, $to === 'planted' ? farm_today() : null, now_utc(), $id, $fid]);
                trace_record($lot, 'stage_changed', ['plot_id' => $cycle['plot_id'], 'payload' => array_filter(['from' => $cycle['stage'], 'to' => $to, 'reason' => $reason])]);
                if ($to === 'closed') {
                    q("UPDATE trace_batches SET status = 'closed', updated_at = ? WHERE id = ? AND farm_id = ?", [now_utc(), $lot, $fid]);
                }
            });
            flash('success', 'Stage: ' . label($to) . '.');
        } elseif ($action === 'harvest') {
            require_can('crops.harvest.record');
            $date = input_date('harvested_on') ?? fail('Give the harvest date.');
            $qty = input_num('quantity') ?? fail('Give the quantity harvested.');
            if ($qty <= 0) {
                fail('The quantity must be above zero.');
            }
            $override = input('override_reason', 500);
            if ($cycle['safe_harvest_on'] && $date < $cycle['safe_harvest_on'] && !$override) {
                fail('A withholding period runs until ' . fdate($cycle['safe_harvest_on']) . '. Harvesting earlier needs a reason.');
            }
            $moisture = input_num('moisture_pct');
            if ($moisture !== null && ($moisture < 0 || $moisture > 100)) {
                fail('Moisture is a percentage.');
            }
            tx(function () use ($cycle, $fid, $id, $date, $qty, $override, $moisture, $lot) {
                $crop = row('SELECT name, variety FROM crops WHERE id = ?', [$cycle['crop_id']]);
                $plot = row('SELECT code FROM farm_plots WHERE id = ?', [$cycle['plot_id']]);
                $name = $crop['name'] . ($crop['variety'] ? " ({$crop['variety']})" : '') . " harvest {$plot['code']}";
                $batch = trace_create_batch('harvest', ['name' => $name, 'quantity' => $qty, 'unit' => $cycle['yield_unit'], 'origin_plot_id' => $cycle['plot_id'],
                    'source_type' => 'crop_harvest'], ['occurred_at' => $date, 'plot_id' => $cycle['plot_id']]);
                $lotRow = row('SELECT * FROM trace_batches WHERE id = ?', [$lot]);
                trace_link($lotRow, $batch, 'derived', $qty, $cycle['yield_unit'], ['occurred_at' => $date]);
                $hid = uuid();
                insert('crop_harvests', ['id' => $hid, 'farm_id' => $fid, 'cycle_id' => $id, 'harvested_on' => $date, 'quantity' => $qty, 'unit' => $cycle['yield_unit'],
                    'quality_grade' => input('quality_grade', 20), 'moisture_pct' => $moisture, 'notes' => input('notes', 1000), 'trace_batch_id' => $batch['id'],
                    'withholding_override_reason' => $override, 'recorded_by' => $_SESSION['uid'], 'created_at' => gmdate('Y-m-d H:i:s.u')]);
                trace_record($batch['id'], 'harvested', ['occurred_at' => $date, 'plot_id' => $cycle['plot_id'], 'subject_type' => 'crop_harvest', 'subject_id' => $hid,
                    'payload' => array_filter(['quantity' => number_format($qty, 3, '.', ''), 'unit' => $cycle['yield_unit'], 'grade' => input('quality_grade', 20),
                        'moisture_pct' => $moisture !== null ? number_format($moisture, 2, '.', '') : null, 'override' => $override])]);
                if ($cycle['stage'] !== 'harvesting') {
                    q("UPDATE crop_cycles SET stage = 'harvesting', updated_at = ? WHERE id = ? AND farm_id = ?", [now_utc(), $id, $fid]);
                }
            });
            flash('success', 'Harvest of ' . qty($qty, $cycle['yield_unit']) . ' recorded as a new batch.');
        }
    }, 'cycle.php', ['id' => $id]);
}

$crop = row('SELECT * FROM crops WHERE id = ?', [$cycle['crop_id']]);
$plot = row('SELECT * FROM farm_plots WHERE id = ?', [$cycle['plot_id']]);
$lot = row('SELECT * FROM trace_batches WHERE id = ?', [$cycle['crop_lot_batch_id']]);
$ops = rows('SELECT o.*, (SELECT GROUP_CONCAT(CONCAT(i.product_name, " ", TRIM(TRAILING "." FROM TRIM(TRAILING "0" FROM i.quantity)), " ", i.unit) SEPARATOR ", ") FROM crop_operation_inputs i WHERE i.operation_id = o.id) AS inputs
    FROM crop_operations o WHERE o.farm_id = ? AND o.cycle_id = ? ORDER BY o.occurred_at DESC', [$fid, $id]);
$obs = rows('SELECT * FROM crop_observations WHERE farm_id = ? AND cycle_id = ? ORDER BY status = \'resolved\', observed_at DESC', [$fid, $id]);
$harvests = rows('SELECT h.*, b.batch_code FROM crop_harvests h JOIN trace_batches b ON b.id = h.trace_batch_id WHERE h.farm_id = ? AND h.cycle_id = ? ORDER BY h.harvested_on', [$fid, $id]);
$cropName = $crop['name'] . ($crop['variety'] ? " ({$crop['variety']})" : '');
$total = array_sum(array_column($harvests, 'quantity'));

page_start("{$cycle['code']} · $cropName on {$plot['code']}");
echo '<p><a href="' . e(url('crops.php')) . '">← All cycles</a></p>';
echo '<div class="kpis">' . kpi('Stage', e(label($cycle['stage']))) . kpi('Area', e(qty($cycle['area_ha'], 'ha'))) . kpi('Harvested', e(qty($total, $cycle['yield_unit'])),
    $cycle['expected_yield'] ? 'Expected ' . qty($cycle['expected_yield'], $cycle['yield_unit']) : null)
    . kpi('Yield per ha', e($cycle['area_ha'] > 0 ? qty(round($total / $cycle['area_ha'], 1), $cycle['yield_unit']) : '—'))
    . kpi('Safe to harvest from', e($cycle['safe_harvest_on'] ? fdate($cycle['safe_harvest_on']) : 'Any time'), 'After the last withholding period') . '</div>';

echo '<div class="grid"><div class="card"><h2>Details</h2><dl class="facts">'
    . '<dt>Planted</dt><dd>' . e(fdate($cycle['planted_on'] ?? $cycle['sown_on'])) . ' · ' . e(label($cycle['planting_method'])) . '</dd>'
    . '<dt>Harvest expected</dt><dd>' . e(fdate($cycle['expected_harvest_on'])) . '</dd>'
    . '<dt>Crop lot</dt><dd>' . ($lot ? '<a class="code" href="' . e(url('batch.php', ['id' => $lot['id']])) . '">' . e($lot['batch_code']) . '</a>' : '—') . '</dd>'
    . '<dt>Notes</dt><dd>' . e($cycle['notes'] ?? '—') . '</dd></dl></div>';
if ($cycle['stage'] !== 'closed' && can('crops.operations.record')) {
    $next = array_slice(STAGES, array_search($cycle['stage'], STAGES, true) + 1);
    echo '<div class="card"><h2>Move to the next stage</h2><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="stage"><div class="fields">'
        . field('Stage', '<select name="stage">' . enum_options($next) . '</select>')
        . field('If closing, why', '<select name="close_reason">' . enum_options(['harvested', 'failed', 'abandoned', 'other']) . '</select>') . '</div>'
        . '<div class="actions"><button>Change stage</button></div></form></div>';
}
echo '</div>';

echo '<div class="card"><h2>Field work</h2>';
table($ops, [
    'Date' => fn ($o) => e(fdate(substr($o['occurred_at'], 0, 10))),
    'Work' => fn ($o) => '<b>' . e(label($o['type'])) . '</b>' . ($o['notes'] ? '<div class="muted">' . e($o['notes']) . '</div>' : ''),
    'Inputs' => fn ($o) => e($o['inputs'] ?? '—'),
    '#Hours' => fn ($o) => e(qty($o['labour_hours'])),
    'Status' => fn ($o) => badge($o['status']),
], 'No field work recorded yet.');
echo '</div>';

echo '<div class="card"><h2>Field reports</h2>';
table($obs, [
    'Seen' => fn ($o) => e(fdate($o['observed_at'])),
    'Report' => fn ($o) => '<b>' . e($o['title']) . '</b> <span class="muted">' . e(label($o['kind'])) . ' · ' . e(label($o['severity'])) . '</span>' . ($o['description'] ? '<div>' . e($o['description']) . '</div>' : '')
        . ($o['resolution_note'] ? '<div class="muted">Resolved: ' . e($o['resolution_note']) . '</div>' : ''),
    'Status' => fn ($o) => badge($o['status']) . ($o['status'] !== 'resolved' && can('crops.operations.record')
        ? '<form method="post" class="row" style="margin-top:.3rem">' . csrf_field() . '<input type="hidden" name="action" value="resolve"><input type="hidden" name="observation_id" value="' . e($o['id']) . '"><input name="note" placeholder="What was done"><button class="small">Resolve</button></form>' : ''),
], 'No reports.');
echo '</div>';

echo '<div class="card"><h2>Harvests</h2>';
table($harvests, [
    'Date' => fn ($h) => e(fdate($h['harvested_on'])),
    '#Quantity' => fn ($h) => e(qty($h['quantity'], $h['unit'])),
    'Grade' => fn ($h) => e($h['quality_grade'] ?? '—'),
    'Batch' => fn ($h) => '<a class="code" href="' . e(url('batch.php', ['id' => $h['trace_batch_id']])) . '">' . e($h['batch_code']) . '</a>',
    'Note' => fn ($h) => e($h['withholding_override_reason'] ? 'Early harvest: ' . $h['withholding_override_reason'] : ($h['notes'] ?? '')),
], 'Not harvested yet.');
echo '</div>';

if ($cycle['stage'] !== 'closed') {
    echo '<div class="grid">';
    if (can('crops.operations.record')) {
        form_start('Record field work');
        echo '<input type="hidden" name="action" value="operation"><div class="fields">'
            . field('Work', '<select name="type" required>' . enum_options(OPERATION_TYPES, null, true) . '</select>')
            . field('Date', '<input type="date" name="occurred_on" required value="' . e(farm_today()) . '">')
            . field('Labour hours', '<input name="labour_hours" inputmode="decimal">')
            . field('Input used', '<input name="product" placeholder="e.g. Urea 46% N">', 'Fertilizer, spray, seed… (optional)')
            . field('Quantity', '<input name="quantity" inputmode="decimal">') . field('Unit', '<input name="unit" placeholder="kg, L">')
            . field('Withholding days', '<input name="withholding_days" inputmode="numeric">', 'From the product label: no harvest before it ends.')
            . field('Notes', '<textarea name="notes"></textarea>') . '</div>';
        form_end('Save');
        form_start('Report a pest, disease or problem');
        echo '<input type="hidden" name="action" value="observation"><div class="fields">'
            . field('What', '<select name="kind" required>' . enum_options(OBSERVATION_KINDS) . '</select>')
            . field('Severity', '<select name="severity">' . enum_options(SEVERITIES, 'low') . '</select>')
            . field('Title', '<input name="title" required maxlength="150" placeholder="Fall armyworm">')
            . field('Part of the crop affected (%)', '<input name="affected_pct" inputmode="decimal">')
            . field('Description', '<textarea name="description"></textarea>') . '</div>';
        form_end('Save report');
    }
    if (can('crops.harvest.record')) {
        form_start('Record a harvest');
        echo '<input type="hidden" name="action" value="harvest"><div class="fields">'
            . field('Date', '<input type="date" name="harvested_on" required value="' . e(farm_today()) . '">')
            . field('Quantity (' . $cycle['yield_unit'] . ')', '<input name="quantity" required inputmode="decimal">')
            . field('Grade', '<input name="quality_grade" maxlength="20">') . field('Moisture %', '<input name="moisture_pct" inputmode="decimal">')
            . ($cycle['safe_harvest_on'] && $cycle['safe_harvest_on'] > farm_today() ? field('Reason to harvest before ' . fdate($cycle['safe_harvest_on']), '<input name="override_reason" maxlength="500">', 'Needed while a withholding period runs.') : '')
            . field('Notes', '<textarea name="notes"></textarea>') . '</div>';
        form_end('Save harvest');
    }
    echo '</div>';
}
page_end();
