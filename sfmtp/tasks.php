<?php
/* Work planning: activities with one task per worker, the verification queue, and finished work. */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/tasks.php';

$farm = require_farm('tasks.view');
$fid = $farm['id'];
$all = scope('tasks.view') === 'all';
$tab = input_in('tab', ['open', 'verify', 'done', 'activities']) ?? 'open';

if (is_post()) {
    require_can('tasks.manage');
    handle(function () use ($fid) {
        $type = row('SELECT * FROM global_activity_types WHERE id = ? AND is_active = 1', [input_id('activity_type_id')]) ?? fail('Choose the kind of work.');
        $planned = input_date('planned_on') ?? farm_today();
        $due = input_date('due_on');
        if ($due && $due < $planned) {
            fail('The due date is before the planned date.');
        }
        $workerIds = array_values(array_filter((array) ($_POST['worker_ids'] ?? []), fn ($w) => is_string($w) && belongs('workers', $w)));
        if (!$workerIds) {
            fail('Choose at least one worker.');
        }
        [$subjectType, $subjectId, $subjectLabel, $plotId, $locId] = ['general', null, null, null, null];
        if ($plot = input_id('plot_id')) {
            $p = farm_row('farm_plots', $plot);
            [$subjectType, $subjectId, $subjectLabel, $plotId] = ['plot', $p['id'], $p['code'] . ' ' . $p['name'], $p['id']];
        } elseif ($loc = input_id('location_id')) {
            $l = farm_row('farm_locations', $loc);
            [$subjectType, $subjectId, $subjectLabel, $locId] = ['location', $l['id'], $l['code'] . ' ' . $l['name'], $l['id']];
        } elseif ($group = input_id('group_id')) {
            $g = farm_row('animal_groups', $group);
            [$subjectType, $subjectId, $subjectLabel] = ['animal_group', $g['id'], $g['code'] . ' ' . $g['name']];
        }
        $title = input('title', 150) ?? trim($type['name'] . ($subjectLabel ? " · $subjectLabel" : ''));
        tx(function () use ($fid, $type, $planned, $due, $workerIds, $subjectType, $subjectId, $subjectLabel, $plotId, $locId, $title) {
            $aid = uuid();
            insert('activities', ['id' => $aid, 'farm_id' => $fid, 'code' => next_code('activities', 'ACT'), 'activity_type_id' => $type['id'], 'module' => $type['module'],
                'title' => $title, 'instructions' => input('instructions', 2000), 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'subject_label' => $subjectLabel,
                'plot_id' => $plotId, 'location_id' => $locId, 'planned_on' => $planned, 'due_on' => $due, 'priority' => input_in('priority', ['low', 'normal', 'high']) ?? 'normal',
                'target_quantity' => input_num('target_quantity'), 'target_unit' => input('target_unit', 20), 'status' => 'open', 'created_by' => $_SESSION['uid'],
                'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
            foreach ($workerIds as $wid) {
                $tid = uuid();
                insert('worker_tasks', ['id' => $tid, 'farm_id' => $fid, 'code' => next_code('worker_tasks', 'TSK'), 'activity_id' => $aid, 'worker_id' => $wid,
                    'assigned_by' => $_SESSION['uid'], 'due_on' => $due ?? $planned, 'status' => 'assigned', 'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
                $uid = val('SELECT fu.user_id FROM workers w JOIN farm_users fu ON fu.id = w.farm_user_id WHERE w.id = ?', [$wid]);
                if ($uid) {
                    notify([$uid], 'task_assigned', "New task: $title", 'Due ' . fdate($due ?? $planned), url('task.php', ['id' => $tid]));
                }
            }
            audit('workforce.activity.created', null, ['type' => 'activity', 'id' => $aid], null, ['title' => $title, 'tasks' => count($workerIds)]);
        });
        flash('success', count($workerIds) . ' task(s) assigned.');
    }, 'tasks.php', ['tab' => 'open']);
}

page_start('Tasks');
$me = my_worker();
$tabs = ['open' => $all ? 'Open tasks' : 'My tasks'];
if (can('tasks.verify')) {
    $tabs['verify'] = 'To verify (' . val("SELECT COUNT(*) FROM worker_tasks WHERE farm_id = ? AND status = 'submitted'", [$fid]) . ')';
}
$tabs['done'] = 'Finished';
if ($all) {
    $tabs['activities'] = 'Activities';
}
tabs($tabs, $tab);

$scopeSql = $all ? '' : ' AND t.worker_id = ' . db()->quote($me['id'] ?? '-');
$statusSql = ['open' => "t.status IN ('assigned','in_progress','paused','rejected','submitted')", 'verify' => "t.status = 'submitted'", 'done' => "t.status IN ('verified','cancelled')"];

if ($tab !== 'activities') {
    $list = rows("SELECT t.*, a.title, a.priority, a.subject_label, w.full_name FROM worker_tasks t JOIN activities a ON a.id = t.activity_id JOIN workers w ON w.id = t.worker_id
        WHERE t.farm_id = ? AND {$statusSql[$tab]}$scopeSql ORDER BY " . ($tab === 'done' ? 't.updated_at DESC LIMIT 200' : 't.due_on IS NULL, t.due_on, t.code'), [$fid]);
    $today = farm_today();
    echo '<div class="card">';
    table($list, [
        'Task' => fn ($t) => '<a href="' . e(url('task.php', ['id' => $t['id']])) . '"><b>' . e($t['code']) . '</b></a> ' . e($t['title']) . ($t['priority'] === 'high' ? ' <span class="badge bad">high</span>' : ''),
        'Worker' => fn ($t) => e($t['full_name']),
        'Due' => fn ($t) => ($t['due_on'] && $t['due_on'] < $today && !in_array($t['status'], ['verified', 'cancelled', 'submitted'], true) ? '<span class="badge bad">' . e(fdate($t['due_on'])) . '</span>' : e(fdate($t['due_on']))),
        'Status' => fn ($t) => badge($t['status']),
        '#Done' => fn ($t) => e(qty($t['quantity'], $t['unit'])),
    ], $tab === 'verify' ? 'Nothing waiting for verification.' : 'No tasks.');
    echo '</div>';
} else {
    $acts = rows('SELECT a.*, t.name AS type_name, (SELECT COUNT(*) FROM worker_tasks x WHERE x.activity_id = a.id) AS tasks,
        (SELECT COUNT(*) FROM worker_tasks x WHERE x.activity_id = a.id AND x.status = \'verified\') AS verified
        FROM activities a JOIN global_activity_types t ON t.id = a.activity_type_id WHERE a.farm_id = ? ORDER BY a.planned_on DESC LIMIT 200', [$fid]);
    echo '<div class="card">';
    table($acts, ['Activity' => fn ($a) => '<b>' . e($a['code']) . '</b> ' . e($a['title']), 'Kind' => fn ($a) => e($a['type_name']), 'Planned' => fn ($a) => e(fdate($a['planned_on'])),
        'Due' => fn ($a) => e(fdate($a['due_on'])), '#Verified' => fn ($a) => e($a['verified'] . ' / ' . $a['tasks']), 'Status' => fn ($a) => badge($a['status'])], 'No activities.');
    echo '</div>';
}

if (can('tasks.manage')) {
    $types = rows('SELECT id, name, module FROM global_activity_types WHERE is_active = 1 ORDER BY module, name');
    $workers = rows("SELECT id, worker_code, full_name, job_title FROM workers WHERE farm_id = ? AND status = 'active' ORDER BY full_name", [$fid]);
    $plots = rows('SELECT id, code, name FROM farm_plots WHERE farm_id = ? AND deleted_at IS NULL ORDER BY code', [$fid]);
    $locs = rows('SELECT id, code, name FROM farm_locations WHERE farm_id = ? AND deleted_at IS NULL ORDER BY code', [$fid]);
    $groups = rows('SELECT id, code, name FROM animal_groups WHERE farm_id = ? AND is_active = 1 ORDER BY code', [$fid]);
    form_start('Plan work and assign it');
    $checks = implode('', array_map(fn ($w) => '<label class="row"><input type="checkbox" name="worker_ids[]" value="' . e($w['id']) . '" style="width:auto"> ' . e($w['full_name']) . ' <span class="muted">' . e($w['job_title'] ?? '') . '</span></label>', $workers));
    echo '<div class="fields">'
        . field('Kind of work', '<select name="activity_type_id" required>' . options($types, 'id', fn ($t) => label($t['module']) . ' · ' . $t['name']) . '</select>')
        . field('Title', '<input name="title" maxlength="150" placeholder="Optional; built from the kind and place">')
        . field('On plot', '<select name="plot_id">' . options($plots, 'id', fn ($p) => $p['code'] . ' ' . $p['name']) . '</select>')
        . field('Or at location', '<select name="location_id">' . options($locs, 'id', fn ($l) => $l['code'] . ' ' . $l['name']) . '</select>')
        . field('Or for animal group', '<select name="group_id">' . options($groups, 'id', fn ($g) => $g['code'] . ' ' . $g['name']) . '</select>')
        . field('Planned for', '<input type="date" name="planned_on" value="' . e(farm_today()) . '">') . field('Due', '<input type="date" name="due_on">')
        . field('Priority', '<select name="priority">' . enum_options(['low', 'normal', 'high'], 'normal') . '</select>')
        . field('Target', '<input name="target_quantity" inputmode="decimal">') . field('Target unit', '<input name="target_unit" placeholder="ha, m, head">')
        . field('Instructions', '<textarea name="instructions"></textarea>')
        . field('Workers', '<div class="stack">' . ($checks ?: '<span class="muted">Add workers first.</span>') . '</div>') . '</div>';
    form_end('Assign');
}
page_end();
