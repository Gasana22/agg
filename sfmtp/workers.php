<?php
/* Workers, daily attendance (check in / out) and leave. */
require __DIR__ . '/inc/bootstrap.php';

$farm = require_farm();
$fid = $farm['id'];
$tab = input_in('tab', ['workers', 'attendance', 'leave']) ?? (can('workers.view') ? 'workers' : 'attendance');
$me = my_worker();
$today = farm_today();

const EMPLOYMENT = ['permanent', 'casual', 'contract', 'seasonal'];
const LEAVE_KINDS = ['annual', 'sick', 'compassionate', 'unpaid', 'other'];

if (is_post()) {
    $action = input('action', 20);
    handle(function () use ($action, $fid, $me, $today) {
        if ($action === 'check_in' || $action === 'check_out') {
            require_can('attendance.record');
            $me ?? fail('Your account is not linked to a worker record. Ask the manager.');
            flash('success', attendance_record($action, $me));
            redirect('dashboard.php');
        } elseif ($action === 'worker') {
            require_can('workers.manage');
            $name = input('full_name', 150) ?? fail('The worker\'s name is required.');
            $member = input_id('farm_user_id');
            if ($member) {
                belongs('farm_users', $member) || fail('Unknown member.');
                val('SELECT 1 FROM workers WHERE farm_id = ? AND farm_user_id = ?', [$fid, $member]) && fail('That member already has a worker record.');
            }
            $rate = can('finance.view') ? input_num('daily_rate') : null;
            $id = uuid();
            insert('workers', ['id' => $id, 'farm_id' => $fid, 'worker_code' => next_code('workers', 'WRK', 3, 'worker_code'), 'farm_user_id' => $member, 'full_name' => $name,
                'phone' => input('phone', 30), 'national_id' => input('national_id', 40), 'job_title' => input('job_title', 80), 'employment_type' => input_in('employment_type', EMPLOYMENT) ?? 'casual',
                'daily_rate' => $rate, 'started_on' => input_date('started_on') ?? $today, 'status' => 'active', 'created_by' => $_SESSION['uid'], 'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
            audit('workforce.worker.created', null, ['type' => 'worker', 'id' => $id], null, ['full_name' => $name]);
            flash('success', "$name added.");
        } elseif ($action === 'manual') {
            require_can('attendance.approve');
            $w = farm_row('workers', input_id('worker_id'));
            $date = input_date('work_date') ?? fail('Give the day.');
            $in = input('in', 5) ?? fail('Give the time in.');
            $out = input('out', 5);
            val('SELECT 1 FROM worker_attendance WHERE farm_id = ? AND worker_id = ? AND work_date = ?', [$fid, $w['id'], $date]) && fail('That day is already recorded.');
            $tz = new DateTimeZone(current_farm()['timezone']);
            $toUtc = fn ($t) => (new DateTime("$date $t", $tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            insert('worker_attendance', ['id' => uuid(), 'farm_id' => $fid, 'worker_id' => $w['id'], 'work_date' => $date, 'check_in_at' => $toUtc($in),
                'check_out_at' => $out ? $toUtc($out) : null, 'source' => 'manual', 'note' => input('note', 500) ?? 'Paper register', 'recorded_by' => $_SESSION['uid'],
                'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
            flash('success', 'Attendance recorded.');
        } elseif ($action === 'leave') {
            require_can('leave.request');
            $me ?? fail('Your account is not linked to a worker record.');
            $from = input_date('from_on') ?? fail('Give the first day.');
            $to = input_date('to_on') ?? $from;
            $to < $from && fail('The last day is before the first.');
            insert('worker_leave', ['id' => uuid(), 'farm_id' => $fid, 'worker_id' => $me['id'], 'kind' => input_in('kind', LEAVE_KINDS) ?? 'annual', 'from_on' => $from, 'to_on' => $to,
                'reason' => input('reason', 500), 'status' => 'requested', 'requested_by' => $_SESSION['uid'], 'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
            flash('success', 'Leave requested.');
        } elseif ($action === 'decide') {
            require_can('leave.approve');
            $l = farm_row('worker_leave', input_id('leave_id'));
            $l['status'] === 'requested' || fail('Already decided.');
            ($me && $me['id'] === $l['worker_id']) && fail('Someone else must decide your own leave.');
            $status = input_in('decision', ['approved', 'rejected']) ?? fail('Approve or reject.');
            q('UPDATE worker_leave SET status = ?, decided_by = ?, decided_at = ?, decision_note = ?, updated_at = ?, version = version + 1 WHERE id = ? AND farm_id = ?',
                [$status, $_SESSION['uid'], now_utc(), input('note', 500), now_utc(), $l['id'], $fid]);
            flash('success', 'Leave ' . $status . '.');
        }
    }, 'workers.php', ['tab' => $action === 'worker' ? 'workers' : ($action === 'leave' || $action === 'decide' ? 'leave' : 'attendance')]);
}

page_start('Workers');
$tabs = [];
if (can('workers.view')) {
    $tabs['workers'] = 'Workers';
}
$tabs['attendance'] = 'Attendance';
$tabs['leave'] = 'Leave';
tabs($tabs, $tab);
$seeAll = can('attendance.view') && scope('attendance.view') === 'all';

if ($tab === 'workers' && can('workers.view')) {
    $workers = rows('SELECT w.*, u.email FROM workers w LEFT JOIN farm_users fu ON fu.id = w.farm_user_id LEFT JOIN users u ON u.id = fu.user_id WHERE w.farm_id = ? ORDER BY w.status, w.full_name', [$fid]);
    $money = can('finance.view');
    $cols = ['Code' => fn ($w) => '<b>' . e($w['worker_code']) . '</b>', 'Name' => fn ($w) => e($w['full_name']) . ($w['email'] ? '<div class="muted">' . e($w['email']) . '</div>' : ''),
        'Job' => fn ($w) => e($w['job_title'] ?? '—'), 'Type' => fn ($w) => e(label($w['employment_type'])), 'Phone' => fn ($w) => e($w['phone'] ?? '—'), 'Status' => fn ($w) => badge($w['status'])];
    if ($money) {
        $cols['#Daily rate'] = fn ($w) => e(money($w['daily_rate']));
    }
    echo '<div class="card">';
    table($workers, $cols, 'No workers yet.');
    echo '</div>';
    if (can('workers.manage')) {
        $members = rows('SELECT fu.id, u.name, u.email FROM farm_users fu JOIN users u ON u.id = fu.user_id WHERE fu.farm_id = ? AND fu.status = \'active\'
            AND NOT EXISTS (SELECT 1 FROM workers w WHERE w.farm_user_id = fu.id) ORDER BY u.name', [$fid]);
        form_start('Add a worker');
        echo '<input type="hidden" name="action" value="worker"><div class="fields">' . field('Full name', '<input name="full_name" required maxlength="150">')
            . field('Job', '<input name="job_title" maxlength="80">') . field('Type', '<select name="employment_type">' . enum_options(EMPLOYMENT, 'casual') . '</select>')
            . field('Phone', '<input name="phone" maxlength="30">') . field('National ID', '<input name="national_id" maxlength="40">')
            . ($money ? field('Daily rate', '<input name="daily_rate" inputmode="decimal">') : '') . field('Started', '<input type="date" name="started_on">')
            . field('Uses the app as', '<select name="farm_user_id">' . options($members, 'id', fn ($m) => $m['name'] . ' (' . $m['email'] . ')') . '</select>', 'Link a member so they see their tasks and can check in.') . '</div>';
        form_end('Add worker');
    }
} elseif ($tab === 'attendance') {
    $day = input_date('day') ?? $today;
    $params = [$fid, $day];
    $where = '';
    if (!$seeAll) {
        $where = ' AND a.worker_id = ?';
        $params[] = $me['id'] ?? '-';
    }
    $att = rows("SELECT a.*, w.full_name FROM worker_attendance a JOIN workers w ON w.id = a.worker_id WHERE a.farm_id = ? AND a.work_date = ?$where ORDER BY a.check_in_at", $params);
    echo '<form class="row no-print" method="get"><input type="hidden" name="tab" value="attendance"><input type="date" name="day" value="' . e($day) . '" style="max-width:180px"><button>Show</button></form><br>';
    echo '<div class="card">';
    table($att, ['Worker' => fn ($a) => e($a['full_name']), 'In' => fn ($a) => e(substr(fdate($a['check_in_at'], true), -5)), 'Out' => fn ($a) => $a['check_out_at'] ? e(substr(fdate($a['check_out_at'], true), -5)) : '<span class="muted">still in</span>',
        '#Hours' => fn ($a) => $a['check_out_at'] ? e(round((strtotime($a['check_out_at']) - strtotime($a['check_in_at'])) / 3600, 1)) : '—',
        'How' => fn ($a) => e(label($a['source'])) . ($a['note'] ? ' · ' . e($a['note']) : '')], 'Nobody checked in that day.');
    echo '</div>';
    if (can('attendance.approve')) {
        $workers = rows("SELECT id, full_name FROM workers WHERE farm_id = ? AND status = 'active' ORDER BY full_name", [$fid]);
        form_start('Record attendance from the paper register');
        echo '<input type="hidden" name="action" value="manual"><div class="fields">' . field('Worker', '<select name="worker_id" required>' . options($workers, 'id', 'full_name') . '</select>')
            . field('Day', '<input type="date" name="work_date" value="' . e($day) . '" required>') . field('In', '<input type="time" name="in" required value="07:00">')
            . field('Out', '<input type="time" name="out" value="16:00">') . field('Note', '<input name="note" placeholder="Paper register">') . '</div>';
        form_end('Save');
    }
} else {
    $params = [$fid];
    $where = '';
    if (!can('leave.approve')) {
        $where = ' AND l.worker_id = ?';
        $params[] = $me['id'] ?? '-';
    }
    $leave = rows("SELECT l.*, w.full_name FROM worker_leave l JOIN workers w ON w.id = l.worker_id WHERE l.farm_id = ?$where ORDER BY l.status = 'requested' DESC, l.from_on DESC LIMIT 100", $params);
    echo '<div class="card">';
    table($leave, ['Worker' => fn ($l) => e($l['full_name']), 'Kind' => fn ($l) => e(label($l['kind'])), 'From' => fn ($l) => e(fdate($l['from_on'])), 'To' => fn ($l) => e(fdate($l['to_on'])),
        'Reason' => fn ($l) => e($l['reason'] ?? ''), 'Status' => fn ($l) => badge($l['status']) . ($l['status'] === 'requested' && can('leave.approve') && (!$me || $me['id'] !== $l['worker_id'])
            ? ' ' . post_button('Approve', ['action' => 'decide', 'leave_id' => $l['id'], 'decision' => 'approved'], 'small') . ' ' . post_button('Reject', ['action' => 'decide', 'leave_id' => $l['id'], 'decision' => 'rejected'], 'small danger') : '')], 'No leave.');
    echo '</div>';
    if ($me && can('leave.request')) {
        form_start('Ask for leave');
        echo '<input type="hidden" name="action" value="leave"><div class="fields">' . field('Kind', '<select name="kind">' . enum_options(LEAVE_KINDS) . '</select>')
            . field('From', '<input type="date" name="from_on" required>') . field('To', '<input type="date" name="to_on">') . field('Reason', '<input name="reason" maxlength="500">') . '</div>';
        form_end('Ask');
    }
}
page_end();
