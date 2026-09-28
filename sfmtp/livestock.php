<?php
/* Livestock: animals, groups, milk and egg records, health care. */
require __DIR__ . '/inc/bootstrap.php';

$farm = require_farm('livestock.animals.view');
$fid = $farm['id'];
$tab = input_in('tab', ['animals', 'groups', 'production', 'health']) ?? 'animals';

const SEXES = ['female', 'male'];
const ORIGINS = ['born', 'purchased', 'gifted', 'other'];
const GROUP_PURPOSES = ['dairy', 'beef', 'meat', 'layers', 'broilers', 'breeding', 'mixed', 'other'];
const PRODUCTS = ['milk', 'eggs', 'wool', 'other'];

if (is_post()) {
    $action = input('action', 20);
    handle(function () use ($action, $fid) {
        require_can($action === 'production' ? 'livestock.records.record' : 'livestock.animals.manage');
        if ($action === 'animal') {
            $species = row('SELECT * FROM global_animal_species WHERE id = ?', [input_id('species_id')]) ?? fail('Choose the species.');
            $breed = input_id('breed_id');
            $sex = input_in('sex', SEXES) ?? fail('Choose the sex.');
            $group = input_id('group_id');
            $tag = input('tag_number', 40);
            if ($tag && val('SELECT 1 FROM animals WHERE farm_id = ? AND tag_number = ?', [$fid, $tag])) {
                fail("Tag $tag is already used.");
            }
            $id = uuid();
            $prefix = strtoupper(substr($species['code'], 0, 3));
            tx(function () use ($id, $fid, $species, $breed, $sex, $group, $tag, $prefix) {
                $code = next_code('animals', $prefix, 3, 'animal_code');
                $batch = trace_create_batch('animal', ['name' => trim($code . ' ' . (input('name', 80) ?? '')), 'quantity' => 1, 'unit' => 'head', 'source_type' => 'animal', 'source_id' => $id]);
                insert('animals', ['id' => $id, 'farm_id' => $fid, 'animal_code' => $code, 'tag_number' => $tag, 'name' => input('name', 80), 'species_id' => $species['id'],
                    'breed_id' => $breed && val('SELECT 1 FROM global_animal_breeds WHERE id = ? AND species_id = ?', [$breed, $species['id']]) ? $breed : null,
                    'sex' => $sex, 'birth_date' => input_date('birth_date'), 'birth_date_estimated' => input('estimated') ? 1 : 0, 'origin' => input_in('origin', ORIGINS) ?? 'purchased',
                    'acquired_on' => input_date('acquired_on'), 'group_id' => $group && belongs('animal_groups', $group) ? $group : null, 'status' => 'active',
                    'trace_batch_id' => $batch['id'], 'notes' => input('notes', 2000), 'created_by' => $_SESSION['uid'], 'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
                trace_record($batch['id'], 'registered', ['subject_type' => 'animal', 'subject_id' => $id, 'payload' => array_filter(['animal_code' => $code, 'species' => $species['name'], 'sex' => $sex, 'tag' => $tag])]);
            });
            flash('success', 'Animal registered.');
            redirect('animal.php', ['id' => $id]);
        } elseif ($action === 'group') {
            $species = input_id('species_id') ?? fail('Choose the species.');
            insert('animal_groups', ['id' => uuid(), 'farm_id' => $fid, 'code' => strtoupper(input('code', 20) ?? fail('Give the group a code.')), 'name' => input('name', 120) ?? fail('Name the group.'),
                'species_id' => $species, 'purpose' => input_in('purpose', GROUP_PURPOSES) ?? 'mixed', 'flock_size' => input_num('flock_size'), 'is_active' => 1,
                'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
            flash('success', 'Group added.');
        } elseif ($action === 'production') {
            $product = input_in('product', PRODUCTS) ?? fail('Choose the product.');
            $animal = input_id('animal_id');
            $group = input_id('group_id');
            if (!($animal && belongs('animals', $animal)) && !($group && belongs('animal_groups', $group))) {
                fail('Choose the animal or the group.');
            }
            $qty = input_num('quantity') ?? fail('Give the quantity.');
            if ($qty <= 0) {
                fail('The quantity must be above zero.');
            }
            $date = input_date('produced_on') ?? farm_today();
            $discard = 0;
            if ($animal && $product === 'milk') {
                $until = val('SELECT milk_withdrawal_until FROM animals WHERE id = ?', [$animal]);
                $discard = $until && $until >= $date ? 1 : 0;
            }
            insert('animal_production_records', ['id' => uuid(), 'farm_id' => $fid, 'animal_id' => $animal ?: null, 'group_id' => $animal ? null : $group, 'product' => $product,
                'produced_on' => $date, 'session' => input_in('session', ['morning', 'midday', 'evening']), 'quantity' => $qty, 'unit' => $product === 'milk' ? 'L' : ($product === 'eggs' ? 'eggs' : 'kg'),
                'discarded' => $discard, 'notes' => input('notes', 500), 'recorded_by' => $_SESSION['uid'], 'created_at' => gmdate('Y-m-d H:i:s.u')]);
            flash($discard ? 'warn' : 'success', $discard ? 'Saved as DISCARDED: this cow is under milk withdrawal until ' . fdate($until) . '.' : 'Saved.');
        }
    }, 'livestock.php', ['tab' => $action === 'group' ? 'groups' : ($action === 'production' ? 'production' : 'animals')]);
}

page_start('Livestock');
tabs(['animals' => 'Animals', 'groups' => 'Groups', 'production' => 'Milk & eggs', 'health' => 'Health'], $tab);
$today = farm_today();
$species = rows('SELECT id, name FROM global_animal_species WHERE is_active = 1 ORDER BY name');
$groups = rows('SELECT g.*, s.name AS species, (SELECT COUNT(*) FROM animals a WHERE a.group_id = g.id AND a.status = \'active\') AS head FROM animal_groups g JOIN global_animal_species s ON s.id = g.species_id WHERE g.farm_id = ? ORDER BY g.code', [$fid]);

if ($tab === 'animals') {
    $status = input_in('status', ['active', 'sold', 'dead', 'culled', 'transferred']) ?? 'active';
    $search = input('q', 60);
    $params = [$fid, $status];
    $where = '';
    if ($search) {
        $where = ' AND (a.animal_code LIKE ? OR a.tag_number LIKE ? OR a.name LIKE ?)';
        array_push($params, "%$search%", "%$search%", "%$search%");
    }
    $animals = rows("SELECT a.*, s.name AS species, b.name AS breed, g.name AS group_name FROM animals a JOIN global_animal_species s ON s.id = a.species_id
        LEFT JOIN global_animal_breeds b ON b.id = a.breed_id LEFT JOIN animal_groups g ON g.id = a.group_id WHERE a.farm_id = ? AND a.status = ?$where ORDER BY a.animal_code", $params);
    echo '<form class="row no-print" method="get"><input type="hidden" name="tab" value="animals"><input name="q" placeholder="Code, tag or name" value="' . e($search) . '" style="max-width:220px">'
        . '<select name="status" style="max-width:160px">' . enum_options(['active', 'sold', 'dead', 'culled', 'transferred'], $status) . '</select><button>Filter</button></form><br>';
    echo '<div class="card">';
    table($animals, [
        'Animal' => fn ($a) => '<a href="' . e(url('animal.php', ['id' => $a['id']])) . '"><b>' . e($a['animal_code']) . '</b></a> ' . e($a['name']),
        'Tag' => fn ($a) => e($a['tag_number'] ?? '—'),
        'Species' => fn ($a) => e($a['species']) . ($a['breed'] ? ' · ' . e($a['breed']) : ''),
        'Sex' => fn ($a) => e(label($a['sex'])),
        'Group' => fn ($a) => e($a['group_name'] ?? '—'),
        '#Weight' => fn ($a) => e(qty($a['last_weight_kg'], 'kg')),
        'Withdrawal' => fn ($a) => ($a['milk_withdrawal_until'] >= $today ? '<span class="badge bad">milk until ' . e(fdate($a['milk_withdrawal_until'])) . '</span> ' : '')
            . ($a['meat_withdrawal_until'] >= $today ? '<span class="badge bad">meat until ' . e(fdate($a['meat_withdrawal_until'])) . '</span>' : ''),
    ], 'No animals.');
    echo '</div>';
    if (can('livestock.animals.manage')) {
        $breeds = rows('SELECT b.id, b.name, s.name AS species FROM global_animal_breeds b JOIN global_animal_species s ON s.id = b.species_id WHERE b.is_active = 1 ORDER BY s.name, b.name');
        form_start('Register an animal');
        echo '<input type="hidden" name="action" value="animal"><div class="fields">'
            . field('Species', '<select name="species_id" required>' . options($species, 'id', 'name') . '</select>')
            . field('Breed', '<select name="breed_id">' . options($breeds, 'id', fn ($b) => $b['species'] . ' · ' . $b['name']) . '</select>')
            . field('Name', '<input name="name" maxlength="80">') . field('Ear tag', '<input name="tag_number" maxlength="40">')
            . field('Sex', '<select name="sex" required>' . enum_options(SEXES) . '</select>')
            . field('Born', '<input type="date" name="birth_date">') . field('Came from', '<select name="origin">' . enum_options(ORIGINS, 'purchased') . '</select>')
            . field('Arrived on', '<input type="date" name="acquired_on">')
            . field('Group', '<select name="group_id">' . options($groups, 'id', fn ($g) => $g['code'] . ' ' . $g['name']) . '</select>')
            . field('Notes', '<textarea name="notes"></textarea>') . '</div>';
        form_end('Register');
    }
} elseif ($tab === 'groups') {
    echo '<div class="card">';
    table($groups, ['Code' => fn ($g) => '<b>' . e($g['code']) . '</b>', 'Name' => fn ($g) => e($g['name']), 'Species' => fn ($g) => e($g['species']),
        'Purpose' => fn ($g) => e(label($g['purpose'])), '#Head' => fn ($g) => e($g['flock_size'] ?? $g['head'])], 'No groups.');
    echo '</div>';
    if (can('livestock.animals.manage')) {
        form_start('Add a group or flock');
        echo '<input type="hidden" name="action" value="group"><div class="fields">' . field('Code', '<input name="code" required maxlength="20" placeholder="LAYERS">')
            . field('Name', '<input name="name" required>') . field('Species', '<select name="species_id" required>' . options($species, 'id', 'name') . '</select>')
            . field('Purpose', '<select name="purpose">' . enum_options(GROUP_PURPOSES, 'mixed') . '</select>') . field('Flock size (birds, fish)', '<input name="flock_size" inputmode="numeric">') . '</div>';
        form_end('Add group');
    }
} elseif ($tab === 'production') {
    $from = date('Y-m-d', strtotime("$today -13 days"));
    $days = rows("SELECT produced_on, product, unit, SUM(CASE WHEN discarded = 0 THEN quantity ELSE 0 END) AS kept, SUM(CASE WHEN discarded = 1 THEN quantity ELSE 0 END) AS discarded
        FROM animal_production_records WHERE farm_id = ? AND produced_on >= ? GROUP BY produced_on, product, unit ORDER BY produced_on DESC, product", [$fid, $from]);
    echo '<div class="card"><h2>Last 14 days</h2>';
    table($days, ['Day' => fn ($d) => e(fdate($d['produced_on'])), 'Product' => fn ($d) => e(label($d['product'])), '#Kept' => fn ($d) => e(qty($d['kept'], $d['unit'])),
        '#Discarded (withdrawal)' => fn ($d) => $d['discarded'] > 0 ? '<span class="badge bad">' . e(qty($d['discarded'], $d['unit'])) . '</span>' : '—'], 'No records.');
    echo '</div>';
    if (can('livestock.records.record')) {
        $animals = rows("SELECT id, animal_code, name FROM animals WHERE farm_id = ? AND status = 'active' AND sex = 'female' ORDER BY animal_code", [$fid]);
        form_start('Record milk or eggs', '', true);
        echo '<input type="hidden" name="action" value="production"><div class="fields">'
            . field('Product', '<select name="product">' . enum_options(PRODUCTS, 'milk') . '</select>')
            . field('Animal', '<select name="animal_id">' . options($animals, 'id', fn ($a) => $a['animal_code'] . ' ' . $a['name']) . '</select>')
            . field('Or group', '<select name="group_id">' . options($groups, 'id', fn ($g) => $g['code'] . ' ' . $g['name']) . '</select>')
            . field('Date', '<input type="date" name="produced_on" value="' . e($today) . '">')
            . field('Session', '<select name="session">' . enum_options(['morning', 'midday', 'evening'], null, true) . '</select>')
            . field('Quantity', '<input name="quantity" required inputmode="decimal">', 'Litres of milk, number of eggs, kg of wool.') . '</div>';
        form_end('Save');
    }
} else {
    $recs = rows('SELECT h.*, a.animal_code, a.name, g.name AS group_name FROM animal_health_records h LEFT JOIN animals a ON a.id = h.animal_id LEFT JOIN animal_groups g ON g.id = h.group_id
        WHERE h.farm_id = ? ORDER BY h.given_on DESC LIMIT 200', [$fid]);
    echo '<div class="card">';
    table($recs, [
        'Date' => fn ($h) => e(fdate($h['given_on'])),
        'Animal' => fn ($h) => $h['animal_id'] ? '<a href="' . e(url('animal.php', ['id' => $h['animal_id']])) . '">' . e($h['animal_code']) . '</a> ' . e($h['name']) : e($h['group_name']),
        'Care' => fn ($h) => '<b>' . e(label($h['kind'])) . '</b> ' . e($h['product_name'] ?? '') . ($h['diagnosis'] ? '<div class="muted">' . e($h['diagnosis']) . '</div>' : ''),
        'Withdrawal' => fn ($h) => e(implode(' · ', array_filter([$h['milk_withdrawal_days'] ? "milk {$h['milk_withdrawal_days']} d" : null, $h['meat_withdrawal_days'] ? "meat {$h['meat_withdrawal_days']} d" : null])) ?: '—'),
        'Next due' => fn ($h) => e(fdate($h['next_due_on'])),
    ], 'No health records.');
    echo '</div><p class="muted">Record treatments and vaccinations on each animal\'s page.</p>';
}
page_end();
