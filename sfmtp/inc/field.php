<?php
/*
 * Field work shared by the web pages and the offline field app (sync.php):
 * attendance, crop field reports, and the crop lot every cycle step is
 * written to.
 */

/**
 * Check in or out. $at is when it happened (UTC, "Y-m-d H:i:s"), which the
 * offline app sends; $gps = [lat, lng, accuracy_m] when the phone gave one.
 */
function attendance_record(string $action, array $worker, ?string $at = null, ?array $gps = null, string $source = 'web'): string
{
    $at ??= gmdate('Y-m-d H:i:s');
    $tz = new DateTimeZone(current_farm()['timezone'] ?? 'Africa/Kampala');
    $day = (new DateTime($at, new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d');
    $fid = farm_id();
    $row = row('SELECT * FROM worker_attendance WHERE farm_id = ? AND worker_id = ? AND work_date = ?', [$fid, $worker['id'], $day]);
    [$lat, $lng, $acc] = $gps && is_numeric($gps[0] ?? null) && is_numeric($gps[1] ?? null) && abs((float) $gps[0]) <= 90 && abs((float) $gps[1]) <= 180
        ? [round((float) $gps[0], 6), round((float) $gps[1], 6), is_numeric($gps[2] ?? null) ? min(99999, round((float) $gps[2], 2)) : null] : [null, null, null];
    if ($action === 'check_in') {
        $row && fail('You already checked in that day.');
        insert('worker_attendance', ['id' => uuid(), 'farm_id' => $fid, 'worker_id' => $worker['id'], 'work_date' => $day, 'check_in_at' => $at . '.000000',
            'check_in_lat' => $lat, 'check_in_lng' => $lng, 'check_in_accuracy_m' => $acc, 'source' => $source, 'recorded_by' => $_SESSION['uid'],
            'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        return 'Checked in. Have a good day.';
    }
    ($row && !$row['check_out_at']) || fail('Check in first.');
    $at . '.000000' > $row['check_in_at'] || fail('Checking out must be after checking in.');
    q('UPDATE worker_attendance SET check_out_at = ?, check_out_lat = ?, check_out_lng = ?, check_out_accuracy_m = ?, updated_at = ?, version = version + 1 WHERE id = ? AND farm_id = ?',
        [$at . '.000000', $lat, $lng, $acc, now_utc(), $row['id'], $fid]);
    return 'Checked out.';
}

/** The cycle's crop lot; older cycles may have none yet, so one is opened on first use. */
function cycle_lot(array $cycle): string
{
    if ($cycle['crop_lot_batch_id']) {
        return $cycle['crop_lot_batch_id'];
    }
    return tx(function () use ($cycle) {
        $names = row('SELECT c.name AS crop, p.code AS plot FROM crops c JOIN farm_plots p ON p.id = ? WHERE c.id = ?', [$cycle['plot_id'], $cycle['crop_id']]);
        $batch = trace_create_batch('crop_lot', ['name' => ($names['crop'] ?? 'Crop') . ' on ' . ($names['plot'] ?? ''), 'origin_plot_id' => $cycle['plot_id'],
            'source_type' => 'crop_cycle', 'source_id' => $cycle['id']], ['plot_id' => $cycle['plot_id']]);
        q('UPDATE crop_cycles SET crop_lot_batch_id = ?, updated_at = ? WHERE id = ? AND farm_id = ?', [$batch['id'], now_utc(), $cycle['id'], farm_id()]);
        return $batch['id'];
    });
}

const OBSERVATION_KINDS = ['pest', 'disease', 'weed', 'nutrient', 'water', 'growth', 'weather', 'other'];
const SEVERITIES = ['low', 'medium', 'high', 'critical'];

/** A field report on a crop cycle (pest, disease …). High and critical reports alert the owners. Returns its id. */
function crop_observation_add(array $cycle, string $kind, string $severity, string $title, ?string $description, ?float $pct, ?string $at = null): string
{
    $cycle['stage'] !== 'closed' || fail('This cycle is closed.');
    in_array($kind, OBSERVATION_KINDS, true) || fail('Choose what you saw.');
    in_array($severity, SEVERITIES, true) || fail('Choose how serious it is.');
    trim($title) !== '' || fail('Give the report a short title.');
    ($pct === null || ($pct >= 0 && $pct <= 100)) || fail('The share affected is a percentage from 0 to 100.');
    $id = uuid();
    tx(function () use ($id, $cycle, $kind, $severity, $title, $description, $pct, $at) {
        $fid = farm_id();
        insert('crop_observations', ['id' => $id, 'farm_id' => $fid, 'cycle_id' => $cycle['id'], 'kind' => $kind, 'severity' => $severity, 'title' => mb_substr($title, 0, 150),
            'description' => $description, 'affected_pct' => $pct, 'observed_at' => $at ?? gmdate('Y-m-d H:i:s'), 'status' => 'open',
            'recorded_by' => $_SESSION['uid'], 'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        trace_record(cycle_lot($cycle), 'observation', ['plot_id' => $cycle['plot_id'], 'subject_type' => 'crop_observation', 'subject_id' => $id,
            'payload' => array_filter(['kind' => $kind, 'title' => mb_substr($title, 0, 150), 'severity' => $severity, 'affected_pct' => $pct !== null ? number_format($pct, 2, '.', '') : null])]);
        if (in_array($severity, ['high', 'critical'], true)) {
            $owners = array_column(rows('SELECT user_id FROM farm_users WHERE farm_id = ? AND is_owner = 1', [$fid]), 'user_id');
            notify($owners, 'pest_report', "$title on {$cycle['code']}", label($severity) . ' ' . $kind . ' report', url('cycle.php', ['id' => $cycle['id']]));
        }
    });
    return $id;
}
