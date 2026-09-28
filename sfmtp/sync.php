<?php
/*
 * Sync for the offline field app (field.php).
 *
 * GET  → what the app needs for the current farm: the person, their worker
 *        record, today's attendance, their open tasks and the crop cycles
 *        they may report on.
 * POST → {"mutations":[{"id":uuid,"farm_id":…,"type":…,"occurred_at":"Y-m-d H:i:s" (UTC),"data":{…}}]}
 *        applied in order. Each mutation id is applied at most once
 *        (sync_mutations); sending it again returns the first result, so a
 *        phone can safely retry after a dropped connection. The answer is
 *        {"results":{id:{"status":"applied"|"rejected","message":…}},"state":…}.
 *
 * Signed-in session cookie plus the X-CSRF-Token header; JSON only.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/tasks.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$user = current_user();
if (!$user || empty($_SESSION['mfa_ok'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'signed_out', 'message' => 'Sign in again to send your work.']));
}
if (is_post() && !hash_equals(csrf_token(), (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    http_response_code(419);
    exit(json_encode(['error' => 'stale_page', 'message' => 'Open the app again to refresh it.']));
}

function field_state(): array
{
    $farm = current_farm();
    if (!$farm) {
        return ['farm' => null];
    }
    $me = my_worker();
    $today = farm_today();
    $tasks = $me ? rows("SELECT t.id, t.code, t.status, t.due_on, a.title, a.instructions, a.subject_label, a.target_quantity, a.target_unit
        FROM worker_tasks t JOIN activities a ON a.id = t.activity_id WHERE t.farm_id = ? AND t.worker_id = ? AND t.status IN ('assigned','in_progress','paused','rejected','submitted')
        ORDER BY t.due_on, t.code", [$farm['id'], $me['id']]) : [];
    $cycles = can('crops.operations.record') ? rows("SELECT c.id, c.code, cr.name AS crop, p.code AS plot FROM crop_cycles c JOIN crops cr ON cr.id = c.crop_id JOIN farm_plots p ON p.id = c.plot_id
        WHERE c.farm_id = ? AND c.stage <> 'closed' ORDER BY p.code", [$farm['id']]) : [];
    return [
        'farm' => ['id' => $farm['id'], 'name' => $farm['name']],
        'user' => ['name' => current_user()['name']],
        'worker' => $me ? ['id' => $me['id'], 'name' => $me['full_name'], 'code' => $me['worker_code']] : null,
        'can' => ['attendance' => $me !== null && can('attendance.record'), 'tasks' => $me !== null && can('tasks.execute'), 'reports' => (bool) $cycles],
        'today' => $today,
        'attendance' => $me ? row('SELECT check_in_at, check_out_at FROM worker_attendance WHERE farm_id = ? AND worker_id = ? AND work_date = ?', [$farm['id'], $me['id'], $today]) : null,
        'tasks' => $tasks,
        'cycles' => $cycles,
        'kinds' => OBSERVATION_KINDS,
        'severities' => SEVERITIES,
        'synced_at' => gmdate('c'),
    ];
}

/** Apply one mutation in its farm. Returns [status, message, entity, record id]. */
function field_apply(array $m): array
{
    $farm = null;
    foreach (my_farms() as $f) {
        if ($f['id'] === ($m['farm_id'] ?? null)) {
            $farm = $f;
        }
    }
    $farm || fail('You are not a member of that farm any more.');
    act_in_farm($farm);
    $at = (string) ($m['occurred_at'] ?? '');
    $t = DateTime::createFromFormat('Y-m-d H:i:s', $at, new DateTimeZone('UTC'));
    ($t && $t->format('Y-m-d H:i:s') === $at) || fail('The time of this action is missing.');
    $t->getTimestamp() <= time() + 300 || fail('This action is dated in the future. Check the phone\'s clock.');
    $t->getTimestamp() >= time() - 14 * 86400 || fail('This action is more than 14 days old and was not sent. Tell the manager.');
    $d = is_array($m['data'] ?? null) ? $m['data'] : [];
    $type = (string) ($m['type'] ?? '');
    switch ($type) {
        case 'attendance.check_in':
        case 'attendance.check_out':
            can('attendance.record') || fail('You may not record attendance.');
            $me = my_worker() ?? fail('Your account is not linked to a worker record. Ask the manager.');
            $gps = isset($d['lat'], $d['lng']) ? [$d['lat'], $d['lng'], $d['accuracy'] ?? null] : null;
            return ['applied', attendance_record(substr($type, 11), $me, $at, $gps, 'mobile'), 'attendance', $me['id']];
        case 'task.start':
        case 'task.pause':
        case 'task.resume':
        case 'task.submit':
            $task = row('SELECT * FROM worker_tasks WHERE id = ? AND farm_id = ?', [uuid_or_null($d['task_id'] ?? null), $farm['id']]) ?? fail('That task no longer exists.');
            can('tasks.execute') || fail('You may not work on tasks.');
            $event = substr($type, 5);
            $qtyv = num($d['quantity'] ?? null);
            task_step($task, $event, array_filter(['occurred_at' => $at, 'quantity' => $qtyv, 'unit' => isset($d['unit']) ? mb_substr((string) $d['unit'], 0, 20) : null,
                'note' => isset($d['note']) ? mb_substr((string) $d['note'], 0, 1000) : null], fn ($v) => $v !== null && $v !== ''));
            return ['applied', "{$task['code']}: " . label(TASK_FLOW[$event][1]), 'worker_task', $task['id']];
        case 'observation.create':
            can('crops.operations.record') || fail('You may not send field reports.');
            $cycle = row('SELECT * FROM crop_cycles WHERE id = ? AND farm_id = ?', [uuid_or_null($d['cycle_id'] ?? null), $farm['id']]) ?? fail('That crop cycle no longer exists.');
            $pct = num($d['affected_pct'] ?? null);
            $id = crop_observation_add($cycle, (string) ($d['kind'] ?? ''), (string) ($d['severity'] ?? 'low'), mb_substr(trim((string) ($d['title'] ?? '')), 0, 150),
                isset($d['description']) ? mb_substr((string) $d['description'], 0, 2000) : null, $pct, $at);
            return ['applied', 'Field report saved.', 'crop_observation', $id];
    }
    fail('Unknown action.');
}

if (is_post()) {
    $in = json_decode((string) file_get_contents('php://input'), true);
    $list = is_array($in['mutations'] ?? null) ? array_slice($in['mutations'], 0, 200) : [];
    $device = uuid_or_null($in['device_id'] ?? null);
    $results = [];
    foreach ($list as $m) {
        $mid = is_array($m) ? uuid_or_null($m['id'] ?? null) : null;
        if (!$mid) {
            continue;
        }
        $farmId = uuid_or_null($m['farm_id'] ?? null) ?? '';
        $seen = row('SELECT status, result FROM sync_mutations WHERE farm_id = ? AND user_id = ? AND mutation_id = ?', [$farmId, $user['id'], $mid]);
        if ($seen) {
            $results[$mid] = ['status' => $seen['status'], 'message' => json_decode($seen['result'], true)['message'] ?? '', 'repeat' => true];
            continue;
        }
        try {
            [$status, $message, $entity, $record] = tx(fn () => field_apply($m));
        } catch (Invalid $e) {
            [$status, $message, $entity, $record] = ['rejected', $e->getMessage(), 'unknown', null];
        } catch (Throwable $e) {
            // Not the phone's fault: keep it queued and try again later.
            error_log('SFMTP sync: ' . $e->getMessage());
            act_in_farm(null);
            $results[$mid] = ['status' => 'retry', 'message' => 'The server could not save this yet; it will be sent again.'];
            continue;
        }
        $results[$mid] = ['status' => $status, 'message' => $message];
        if ($farmId && in_array($farmId, array_column(my_farms(), 'id'), true)) {
            q('INSERT IGNORE INTO sync_mutations (id, farm_id, user_id, mutation_id, device_id, entity, op, record_id, status, result, occurred_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [uuid(), $farmId, $user['id'], $mid, $device, $entity, mb_substr((string) ($m['type'] ?? ''), 0, 20), $record, $status, json_encode(['message' => $message]),
                    is_string($m['occurred_at'] ?? null) && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $m['occurred_at']) ? $m['occurred_at'] : null, gmdate('Y-m-d H:i:s.u')]);
        }
        act_in_farm(null);
    }
    echo json_encode(['results' => $results, 'state' => field_state()]);
    exit;
}
echo json_encode(['state' => field_state()]);
