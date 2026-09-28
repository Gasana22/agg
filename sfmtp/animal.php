<?php
/* One animal: weights, health care with withdrawal periods, milk, moves and exit. */
require __DIR__ . '/inc/bootstrap.php';

$farm = require_farm('livestock.animals.view');
$fid = $farm['id'];
$a = farm_row('animals', input_id('id'));
$id = $a['id'];

const HEALTH_KINDS = ['treatment', 'vaccination', 'deworming', 'checkup', 'injury', 'other'];

if (is_post()) {
    $action = input('action', 20);
    handle(function () use ($action, $a, $fid, $id) {
        if ($a['status'] !== 'active') {
            fail('This animal has left the farm.');
        }
        $batch = $a['trace_batch_id'];
        if ($action === 'weight') {
            require_can('livestock.records.record');
            $kg = input_num('weight_kg') ?? fail('Give the weight.');
            if ($kg <= 0 || $kg > 3000) {
                fail('The weight looks wrong.');
            }
            $date = input_date('weighed_on') ?? farm_today();
            tx(function () use ($fid, $id, $kg, $date, $batch, $a) {
                insert('animal_weights', ['id' => uuid(), 'farm_id' => $fid, 'animal_id' => $id, 'weighed_on' => $date, 'weight_kg' => $kg,
                    'method' => input_in('method', ['scale', 'tape', 'estimate']) ?? 'scale', 'recorded_by' => $_SESSION['uid'], 'created_at' => gmdate('Y-m-d H:i:s.u')]);
                if (!$a['last_weighed_on'] || $date >= $a['last_weighed_on']) {
                    q('UPDATE animals SET last_weight_kg = ?, last_weighed_on = ?, updated_at = ? WHERE id = ? AND farm_id = ?', [$kg, $date, now_utc(), $id, $fid]);
                }
                if ($batch) {
                    trace_record($batch, 'weighed', ['occurred_at' => $date, 'payload' => ['weight_kg' => number_format($kg, 2, '.', '')]]);
                }
            });
            flash('success', 'Weight saved.');
        } elseif ($action === 'health') {
            require_can('livestock.records.record');
            $kind = input_in('kind', HEALTH_KINDS) ?? fail('Choose the kind of care.');
            $date = input_date('given_on') ?? farm_today();
            $milk = input_num('milk_withdrawal_days');
            $meat = input_num('meat_withdrawal_days');
            tx(function () use ($fid, $id, $kind, $date, $milk, $meat, $batch, $a) {
                insert('animal_health_records', ['id' => uuid(), 'farm_id' => $fid, 'animal_id' => $id, 'kind' => $kind, 'given_on' => $date, 'diagnosis' => input('diagnosis', 200),
                    'product_name' => input('product_name', 150), 'dose' => input_num('dose'), 'dose_unit' => input('dose_unit', 20), 'meat_withdrawal_days' => $meat, 'milk_withdrawal_days' => $milk,
                    'next_due_on' => input_date('next_due_on'), 'given_by' => input('given_by', 120), 'notes' => input('notes', 1000), 'recorded_by' => $_SESSION['uid'], 'created_at' => gmdate('Y-m-d H:i:s.u')]);
                $milkUntil = $milk ? date('Y-m-d', strtotime("$date +" . (int) $milk . ' days')) : null;
                $meatUntil = $meat ? date('Y-m-d', strtotime("$date +" . (int) $meat . ' days')) : null;
                // Keep the later of the running and the new withdrawal end dates.
                $later = fn (?string $x, ?string $y) => $x === null ? $y : ($y === null ? $x : max($x, $y));
                q('UPDATE animals SET milk_withdrawal_until = ?, meat_withdrawal_until = ?, updated_at = ? WHERE id = ? AND farm_id = ?',
                    [$later($a['milk_withdrawal_until'], $milkUntil), $later($a['meat_withdrawal_until'], $meatUntil), now_utc(), $id, $fid]);
                if ($batch) {
                    $event = ['vaccination' => 'vaccinated', 'deworming' => 'dewormed'][$kind] ?? 'treated';
                    trace_record($batch, $event, ['occurred_at' => $date, 'payload' => array_filter(['kind' => $kind, 'product' => input('product_name', 150),
                        'milk_withdrawal_days' => $milk !== null ? (int) $milk : null, 'meat_withdrawal_days' => $meat !== null ? (int) $meat : null])]);
                }
            });
            flash('success', 'Health record saved.' . ($milk || $meat ? ' Withdrawal period set.' : ''));
        } elseif ($action === 'move') {
            require_can('livestock.animals.manage');
            $group = input_id('group_id');
            $loc = input_id('location_id');
            q('UPDATE animals SET group_id = ?, location_id = ?, updated_at = ?, version = version + 1 WHERE id = ? AND farm_id = ?',
                [$group && belongs('animal_groups', $group) ? $group : null, $loc && belongs('farm_locations', $loc) ? $loc : null, now_utc(), $id, $fid]);
            if ($batch) {
                trace_record($batch, 'moved', ['payload' => array_filter(['group_id' => $group, 'location_id' => $loc])]);
            }
            flash('success', 'Moved.');
        } elseif ($action === 'exit') {
            require_can('livestock.animals.manage');
            $status = input_in('status', ['sold', 'dead', 'culled', 'transferred']) ?? fail('Choose why the animal left.');
            $date = input_date('exited_on') ?? farm_today();
            $reason = input('exit_reason', 500);
            q('UPDATE animals SET status = ?, exited_on = ?, exit_reason = ?, updated_at = ?, version = version + 1 WHERE id = ? AND farm_id = ?', [$status, $date, $reason, now_utc(), $id, $fid]);
            if ($batch) {
                trace_record($batch, $status === 'dead' ? 'died' : 'status_changed', ['occurred_at' => $date, 'payload' => array_filter(['status' => $status, 'reason' => $reason])]);
                if ($status !== 'sold') {
                    q("UPDATE trace_batches SET status = 'closed' WHERE id = ? AND farm_id = ?", [$batch, $fid]);
                }
            }
            audit('livestock.animal.exited', null, ['type' => 'animal', 'id' => $id], ['status' => 'active'], ['status' => $status]);
            flash('success', 'Recorded.');
        }
    }, 'animal.php', ['id' => $id]);
}

$species = row('SELECT name FROM global_animal_species WHERE id = ?', [$a['species_id']]);
$breed = $a['breed_id'] ? val('SELECT name FROM global_animal_breeds WHERE id = ?', [$a['breed_id']]) : null;
$group = $a['group_id'] ? row('SELECT code, name FROM animal_groups WHERE id = ?', [$a['group_id']]) : null;
$location = $a['location_id'] ? row('SELECT code, name FROM farm_locations WHERE id = ?', [$a['location_id']]) : null;
$dam = $a['dam_id'] ? row('SELECT id, animal_code, name FROM animals WHERE id = ? AND farm_id = ?', [$a['dam_id'], $fid]) : null;
$sire = $a['sire_id'] ? row('SELECT id, animal_code, name FROM animals WHERE id = ? AND farm_id = ?', [$a['sire_id'], $fid]) : null;
$weights = rows('SELECT * FROM animal_weights WHERE farm_id = ? AND animal_id = ? ORDER BY weighed_on DESC LIMIT 20', [$fid, $id]);
$health = rows('SELECT * FROM animal_health_records WHERE farm_id = ? AND animal_id = ? ORDER BY given_on DESC', [$fid, $id]);
$milk = rows("SELECT produced_on, SUM(quantity) AS q, MAX(discarded) AS discarded FROM animal_production_records WHERE farm_id = ? AND animal_id = ? AND product = 'milk' GROUP BY produced_on ORDER BY produced_on DESC LIMIT 14", [$fid, $id]);
$today = farm_today();

page_start($a['animal_code'] . ($a['name'] ? ' · ' . $a['name'] : ''));
echo '<p><a href="' . e(url('livestock.php')) . '">← All animals</a></p>';
if ($a['milk_withdrawal_until'] >= $today || $a['meat_withdrawal_until'] >= $today) {
    echo '<div class="flash error">Under withdrawal: ' . ($a['milk_withdrawal_until'] >= $today ? 'milk must be discarded until ' . e(fdate($a['milk_withdrawal_until'])) . '. ' : '')
        . ($a['meat_withdrawal_until'] >= $today ? 'Not for slaughter or sale until ' . e(fdate($a['meat_withdrawal_until'])) . '.' : '') . '</div>';
}
echo '<div class="grid"><div class="card"><h2>Details</h2><dl class="facts">'
    . '<dt>Status</dt><dd>' . badge($a['status']) . ($a['exited_on'] ? ' ' . e(fdate($a['exited_on'])) . ' ' . e($a['exit_reason']) : '') . '</dd>'
    . '<dt>Species</dt><dd>' . e($species['name']) . ($breed ? ' · ' . e($breed) : '') . ' · ' . e(label($a['sex'])) . '</dd>'
    . '<dt>Ear tag</dt><dd>' . e($a['tag_number'] ?? '—') . '</dd>'
    . '<dt>Born</dt><dd>' . e(fdate($a['birth_date'])) . ($a['birth_date_estimated'] ? ' (estimated)' : '') . ' · ' . e(label($a['origin'])) . '</dd>'
    . '<dt>Parents</dt><dd>' . ($dam ? 'Dam <a href="?id=' . e($dam['id']) . '">' . e($dam['animal_code'] . ' ' . $dam['name']) . '</a> ' : '') . ($sire ? 'Sire <a href="?id=' . e($sire['id']) . '">' . e($sire['animal_code'] . ' ' . $sire['name']) . '</a>' : '') . ($dam || $sire ? '' : '—') . '</dd>'
    . '<dt>Group</dt><dd>' . e($group ? $group['code'] . ' ' . $group['name'] : '—') . '</dd>'
    . '<dt>Location</dt><dd>' . e($location ? $location['code'] . ' ' . $location['name'] : '—') . '</dd>'
    . '<dt>Last weight</dt><dd>' . e(qty($a['last_weight_kg'], 'kg')) . ' · ' . e(fdate($a['last_weighed_on'])) . '</dd>'
    . '<dt>History</dt><dd>' . ($a['trace_batch_id'] ? '<a href="' . e(url('batch.php', ['id' => $a['trace_batch_id']])) . '">Traceability record</a>' : '—') . '</dd></dl></div>';

echo '<div class="card"><h2>Weights</h2>';
table($weights, ['Date' => fn ($w) => e(fdate($w['weighed_on'])), '#Weight' => fn ($w) => e(qty($w['weight_kg'], 'kg')), 'How' => fn ($w) => e(label($w['method']))], 'Not weighed yet.');
echo '</div></div>';

echo '<div class="card"><h2>Health</h2>';
table($health, [
    'Date' => fn ($h) => e(fdate($h['given_on'])),
    'Care' => fn ($h) => '<b>' . e(label($h['kind'])) . '</b> ' . e($h['product_name'] ?? '') . ($h['dose'] ? ' · ' . e(qty($h['dose'], $h['dose_unit'])) : '') . ($h['diagnosis'] ? '<div class="muted">' . e($h['diagnosis']) . '</div>' : ''),
    'Withdrawal' => fn ($h) => e(implode(' · ', array_filter([$h['milk_withdrawal_days'] ? "milk {$h['milk_withdrawal_days']} d" : null, $h['meat_withdrawal_days'] ? "meat {$h['meat_withdrawal_days']} d" : null])) ?: '—'),
    'Next due' => fn ($h) => e(fdate($h['next_due_on'])),
    'By' => fn ($h) => e($h['given_by'] ?? ''),
], 'No health records.');
echo '</div>';
if ($milk) {
    echo '<div class="card"><h2>Milk (last 14 days)</h2>';
    table($milk, ['Day' => fn ($m) => e(fdate($m['produced_on'])), '#Litres' => fn ($m) => e(qty($m['q'], 'L')), '' => fn ($m) => $m['discarded'] ? '<span class="badge bad">discarded</span>' : '']);
    echo '</div>';
}

if ($a['status'] === 'active') {
    echo '<div class="grid">';
    if (can('livestock.records.record')) {
        form_start('Record a weight');
        echo '<input type="hidden" name="action" value="weight"><div class="fields">' . field('Weight (kg)', '<input name="weight_kg" required inputmode="decimal">')
            . field('Date', '<input type="date" name="weighed_on" value="' . e($today) . '">') . field('How', '<select name="method">' . enum_options(['scale', 'tape', 'estimate'], 'scale') . '</select>') . '</div>';
        form_end('Save weight');
        form_start('Record health care');
        echo '<input type="hidden" name="action" value="health"><div class="fields">'
            . field('Care', '<select name="kind" required>' . enum_options(HEALTH_KINDS) . '</select>') . field('Date', '<input type="date" name="given_on" value="' . e($today) . '">')
            . field('Diagnosis', '<input name="diagnosis" maxlength="200">') . field('Drug or vaccine', '<input name="product_name" maxlength="150">')
            . field('Dose', '<input name="dose" inputmode="decimal">') . field('Dose unit', '<input name="dose_unit" placeholder="ml">')
            . field('Milk withdrawal (days)', '<input name="milk_withdrawal_days" inputmode="numeric">', 'From the label. Milk is discarded until it ends.')
            . field('Meat withdrawal (days)', '<input name="meat_withdrawal_days" inputmode="numeric">')
            . field('Next due', '<input type="date" name="next_due_on">') . field('Given by', '<input name="given_by" maxlength="120">') . '</div>';
        form_end('Save');
    }
    if (can('livestock.animals.manage')) {
        $groups = rows('SELECT id, code, name FROM animal_groups WHERE farm_id = ? AND is_active = 1 ORDER BY code', [$fid]);
        $locs = rows('SELECT id, code, name FROM farm_locations WHERE farm_id = ? AND deleted_at IS NULL ORDER BY code', [$fid]);
        form_start('Move to another group or place');
        echo '<input type="hidden" name="action" value="move"><div class="fields">' . field('Group', '<select name="group_id">' . options($groups, 'id', fn ($g) => $g['code'] . ' ' . $g['name'], $a['group_id']) . '</select>')
            . field('Location', '<select name="location_id">' . options($locs, 'id', fn ($l) => $l['code'] . ' ' . $l['name'], $a['location_id']) . '</select>') . '</div>';
        form_end('Move');
        form_start('Record that it left the farm');
        echo '<input type="hidden" name="action" value="exit"><div class="fields">' . field('Why', '<select name="status" required>' . enum_options(['sold', 'dead', 'culled', 'transferred']) . '</select>')
            . field('Date', '<input type="date" name="exited_on" value="' . e($today) . '">') . field('Details', '<input name="exit_reason" maxlength="500">') . '</div>';
        form_end('Save');
    }
    echo '</div>';
}
page_end();
