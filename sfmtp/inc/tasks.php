<?php
/* The task state machine, shared by the task pages. */

const TASK_FLOW = [
    // event => [from states, to state, permission]
    'start' => [['assigned', 'rejected'], 'in_progress', 'tasks.execute'],
    'pause' => [['in_progress'], 'paused', 'tasks.execute'],
    'resume' => [['paused'], 'in_progress', 'tasks.execute'],
    'submit' => [['in_progress', 'paused'], 'submitted', 'tasks.execute'],
    'verify' => [['submitted'], 'verified', 'tasks.verify'],
    'reject' => [['submitted'], 'rejected', 'tasks.verify'],
    'cancel' => [['assigned', 'in_progress', 'paused', 'rejected'], 'cancelled', 'tasks.manage'],
    'note' => [['assigned', 'in_progress', 'paused', 'submitted', 'rejected'], null, 'tasks.execute'],
];

/** Tasks the member may see: all, or only their own (field workers). */
function task_visible(array $task): bool
{
    if (scope('tasks.view') === 'all') {
        return true;
    }
    $w = my_worker();
    return $w !== null && $w['id'] === $task['worker_id'];
}

/** Apply an event to a task; returns the new status. */
function task_step(array $task, string $event, array $data = []): string
{
    [$from, $to, $perm] = TASK_FLOW[$event] ?? fail('Unknown action.');
    require_can($perm);
    if ($perm === 'tasks.execute' && scope('tasks.execute') !== 'all') {
        $w = my_worker();
        if (!$w || $w['id'] !== $task['worker_id']) {
            fail('This task is assigned to someone else.');
        }
    }
    if ($event === 'verify' || $event === 'reject') {
        $w = my_worker();
        if ($w && $w['id'] === $task['worker_id']) {
            fail('Someone else must verify your own work.');
        }
        if ($event === 'reject' && empty($data['note'])) {
            fail('Say why the work is rejected, so it can be redone.');
        }
    }
    if (!in_array($task['status'], $from, true)) {
        fail('This task is ' . label($task['status']) . ', so it cannot be ' . ($event === 'note' ? 'noted' : $event . 'ed') . ' now.');
    }
    $now = gmdate('Y-m-d H:i:s.u');
    return tx(function () use ($task, $event, $to, $data, $now) {
        $fid = farm_id();
        $new = $to ?? $task['status'];
        $set = ['status' => $new, 'updated_at' => now_utc()];
        if ($event === 'start' && !$task['started_at']) {
            $set['started_at'] = $now;
        }
        if ($event === 'submit') {
            $set['submitted_at'] = $now;
            $set['quantity'] = $data['quantity'] ?? null;
            $set['unit'] = $data['unit'] ?? null;
            $set['submit_note'] = $data['note'] ?? null;
            $set['worked_minutes'] = task_minutes($task['id'], $now);
        }
        if ($event === 'verify' || $event === 'reject') {
            $set['verified_by'] = $_SESSION['uid'];
            $set['verified_at'] = now_utc();
            $set['review_note'] = $data['note'] ?? null;
        }
        $sets = implode(', ', array_map(fn ($k) => "`$k` = ?", array_keys($set)));
        q("UPDATE worker_tasks SET $sets, version = version + 1 WHERE id = ? AND farm_id = ?", [...array_values($set), $task['id'], $fid]);
        insert('worker_task_logs', ['id' => uuid(), 'farm_id' => $fid, 'task_id' => $task['id'], 'event' => $event, 'from_status' => $task['status'], 'to_status' => $to,
            'applied' => 1, 'occurred_at' => $now, 'quantity' => $data['quantity'] ?? null, 'unit' => $data['unit'] ?? null, 'note' => $data['note'] ?? null,
            'recorded_by' => $_SESSION['uid'], 'created_at' => $now]);

        $activity = row('SELECT * FROM activities WHERE id = ?', [$task['activity_id']]);
        $worker = row('SELECT w.*, fu.user_id FROM workers w LEFT JOIN farm_users fu ON fu.id = w.farm_user_id WHERE w.id = ?', [$task['worker_id']]);
        if ($event === 'submit') {
            $verifiers = array_column(rows("SELECT DISTINCT fu.user_id FROM farm_users fu JOIN farm_user_roles fur ON fur.farm_user_id = fu.id
                JOIN farm_role_permissions frp ON frp.farm_role_id = fur.farm_role_id JOIN permissions p ON p.id = frp.permission_id
                WHERE fu.farm_id = ? AND fu.status = 'active' AND p.`key` = 'tasks.verify'", [$fid]), 'user_id');
            notify(array_diff($verifiers, [$worker['user_id'] ?? '']), 'task_submitted', "{$task['code']} done: {$activity['title']}", $worker['full_name'] . ' submitted the work.', url('task.php', ['id' => $task['id']]));
        }
        if ($event === 'reject' && !empty($worker['user_id'])) {
            notify([$worker['user_id']], 'task_rejected', "{$task['code']} needs rework", $data['note'] ?? null, url('task.php', ['id' => $task['id']]));
        }
        // An activity is complete when none of its tasks is left to do.
        if (in_array($new, ['verified', 'cancelled'], true)
            && !val("SELECT 1 FROM worker_tasks WHERE activity_id = ? AND status NOT IN ('verified','cancelled')", [$task['activity_id']])) {
            q("UPDATE activities SET status = 'completed', completed_at = ?, updated_at = ? WHERE id = ? AND farm_id = ?", [now_utc(), now_utc(), $task['activity_id'], $fid]);
        }
        return $new;
    });
}

/** Minutes spent in progress, from the task's own history. */
function task_minutes(string $taskId, string $until): int
{
    $total = 0;
    $since = null;
    foreach (rows('SELECT event, occurred_at FROM worker_task_logs WHERE task_id = ? ORDER BY occurred_at', [$taskId]) as $l) {
        if (in_array($l['event'], ['start', 'resume'], true)) {
            $since = strtotime($l['occurred_at'] . ' UTC');
        } elseif (in_array($l['event'], ['pause', 'submit'], true) && $since) {
            $total += strtotime($l['occurred_at'] . ' UTC') - $since;
            $since = null;
        }
    }
    if ($since) {
        $total += strtotime($until . ' UTC') - $since;
    }
    return (int) round($total / 60);
}
