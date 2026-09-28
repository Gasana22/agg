<?php
/* One task: the worker starts, pauses and submits it; a manager verifies or rejects it. */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/tasks.php';

$farm = require_farm('tasks.view');
$task = farm_row('worker_tasks', input_id('id'));
if (!task_visible($task)) {
    http_response_code(404);
    page_start('Not found');
    echo '<div class="card">This task is not yours.</div>';
    page_end();
    exit;
}

if (is_post()) {
    handle(function () use ($task) {
        $event = input_in('event', array_keys(TASK_FLOW)) ?? fail('Unknown action.');
        $data = ['note' => input('note', 2000), 'quantity' => input_num('quantity'), 'unit' => input('unit', 20)];
        if ($event === 'note' && !$data['note']) {
            fail('Write the note first.');
        }
        $new = task_step($task, $event, $data);
        flash('success', $event === 'note' ? 'Note added.' : 'Task is now ' . label($new) . '.');
    }, 'task.php', ['id' => $task['id']]);
}

$activity = row('SELECT a.*, t.name AS type_name FROM activities a JOIN global_activity_types t ON t.id = a.activity_type_id WHERE a.id = ?', [$task['activity_id']]);
$worker = row('SELECT * FROM workers WHERE id = ?', [$task['worker_id']]);
$logs = rows('SELECT l.*, u.name AS by_name FROM worker_task_logs l LEFT JOIN users u ON u.id = l.recorded_by WHERE l.task_id = ? AND l.farm_id = ? ORDER BY l.occurred_at', [$task['id'], $farm['id']]);

page_start($task['code'] . ' · ' . $activity['title']);
echo '<p><a href="' . e(url('tasks.php')) . '">← Tasks</a></p><div class="grid"><div class="card"><h2>Task</h2><dl class="facts">'
    . '<dt>Status</dt><dd>' . badge($task['status']) . '</dd>'
    . '<dt>Worker</dt><dd>' . e($worker['full_name']) . '</dd>'
    . '<dt>Kind</dt><dd>' . e($activity['type_name']) . ($activity['subject_label'] ? ' · ' . e($activity['subject_label']) : '') . '</dd>'
    . '<dt>Due</dt><dd>' . e(fdate($task['due_on'])) . ' · priority ' . e($activity['priority']) . '</dd>'
    . '<dt>Target</dt><dd>' . e(qty($activity['target_quantity'], $activity['target_unit'])) . '</dd>'
    . '<dt>Instructions</dt><dd>' . nl2br(e($activity['instructions'] ?? '—')) . '</dd>'
    . '<dt>Done</dt><dd>' . e(qty($task['quantity'], $task['unit'])) . ($task['worked_minutes'] ? ' in ' . e(intdiv((int) $task['worked_minutes'], 60) . ' h ' . ((int) $task['worked_minutes'] % 60) . ' min') : '') . '</dd>'
    . ($task['submit_note'] ? '<dt>Worker note</dt><dd>' . e($task['submit_note']) . '</dd>' : '')
    . ($task['review_note'] ? '<dt>Review</dt><dd>' . e($task['review_note']) . '</dd>' : '') . '</dl></div>';

// The buttons this member may press now.
$mine = ($w = my_worker()) && $w['id'] === $task['worker_id'];
$execute = can('tasks.execute') && ($mine || scope('tasks.execute') === 'all');
echo '<div class="card"><h2>Actions</h2><div class="stack">';
$btn = fn ($event, $text, $class = '') => post_button($text, ['event' => $event], $class);
if ($execute) {
    $s = $task['status'];
    if (in_array($s, ['assigned', 'rejected'], true)) {
        echo $btn('start', $s === 'rejected' ? 'Start rework' : 'Start', 'primary');
    }
    if ($s === 'in_progress') {
        echo $btn('pause', 'Pause') . ' ';
    }
    if ($s === 'paused') {
        echo $btn('resume', 'Resume', 'primary') . ' ';
    }
    if (in_array($s, ['in_progress', 'paused'], true)) {
        echo '<form method="post" class="card" style="margin-top:.6rem">' . csrf_field() . '<input type="hidden" name="event" value="submit"><div class="fields">'
            . field('Quantity done', '<input name="quantity" inputmode="decimal">') . field('Unit', '<input name="unit" value="' . e($activity['target_unit'] ?? '') . '">')
            . field('Note', '<textarea name="note"></textarea>') . '</div><div class="actions"><button class="primary">Submit as done</button></div></form>';
    }
}
if (can('tasks.verify') && $task['status'] === 'submitted' && !$mine) {
    echo '<form method="post" class="card">' . csrf_field() . field('Review note', '<textarea name="note" placeholder="Needed to reject"></textarea>')
        . '<div class="actions"><button class="primary" name="event" value="verify">Verify</button><button class="danger" name="event" value="reject">Reject</button></div></form>';
}
if (can('tasks.manage') && in_array($task['status'], TASK_FLOW['cancel'][0], true)) {
    echo post_button('Cancel task', ['event' => 'cancel'], 'danger', 'Cancel this task?');
}
if ($execute && TASK_FLOW['note'][0] && in_array($task['status'], TASK_FLOW['note'][0], true)) {
    echo '<form method="post" class="row" style="margin-top:.6rem">' . csrf_field() . '<input type="hidden" name="event" value="note"><input name="note" placeholder="Add a note"><button>Add</button></form>';
}
echo '</div></div></div>';

echo '<div class="card"><h2>History</h2><ul class="timeline">';
foreach ($logs as $l) {
    echo '<li><b>' . e(label($l['event'])) . '</b> ' . ($l['to_status'] ? '→ ' . e(label($l['to_status'])) : '') . ' <span class="muted">' . e(fdate($l['occurred_at'], true)) . ' · ' . e($l['by_name'] ?? '') . '</span>'
        . ($l['note'] ? '<div>' . e($l['note']) . '</div>' : '') . ($l['quantity'] ? '<div class="muted">' . e(qty($l['quantity'], $l['unit'])) . '</div>' : '') . '</li>';
}
echo $logs ? '' : '<li class="muted">Assigned, not started yet.</li>';
echo '</ul></div>';
page_end();
