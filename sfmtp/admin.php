<?php
/* Platform administration: approve, suspend and reopen farms; accounts. Platform staff only. */
require __DIR__ . '/inc/bootstrap.php';

$user = require_login();
if (!is_platform_admin($user)) {
    http_response_code(403);
    page_start('Not allowed');
    echo '<div class="card">This area is for platform staff.</div>';
    page_end();
    exit;
}
$tab = input_in('tab', ['farms', 'accounts']) ?? 'farms';

if (is_post()) {
    $action = input('action', 20);
    handle(function () use ($action) {
        if ($action === 'farm') {
            $farm = row('SELECT * FROM farms WHERE id = ?', [input_id('farm_id')]) ?? fail('Unknown farm.');
            $to = input_in('status', ['active', 'suspended', 'closed']) ?? fail('Choose the new status.');
            q('UPDATE farms SET status = ?, approved_at = COALESCE(approved_at, IF(? = \'active\', ?, NULL)), approved_by = COALESCE(approved_by, IF(? = \'active\', ?, NULL)),
                suspended_at = IF(? = \'suspended\', ?, NULL), closed_at = IF(? = \'closed\', ?, closed_at), updated_at = ? WHERE id = ?',
                [$to, $to, now_utc(), $to, $_SESSION['uid'], $to, now_utc(), $to, now_utc(), now_utc(), $farm['id']]);
            insert('farm_status_history', ['id' => uuid(), 'farm_id' => $farm['id'], 'from_status' => $farm['status'], 'to_status' => $to, 'reason_code' => input('reason', 30),
                'note' => input('note', 500), 'changed_by' => $_SESSION['uid'], 'created_at' => now_utc()]);
            audit('platform.farm.' . $to, $farm['id'], ['type' => 'farm', 'id' => $farm['id']], ['status' => $farm['status']], ['status' => $to]);
            flash('success', "{$farm['name']} is now $to.");
        } elseif ($action === 'account') {
            $u = row('SELECT * FROM users WHERE id = ?', [input_id('user_id')]) ?? fail('Unknown account.');
            $u['id'] === $_SESSION['uid'] && fail('You cannot change your own account here.');
            $status = input_in('status', ['active', 'disabled']) ?? fail('Choose the status.');
            q('UPDATE users SET status = ?, failed_logins = 0, locked_until = NULL, updated_at = ? WHERE id = ?', [$status, now_utc(), $u['id']]);
            audit('platform.account.' . $status, null, ['type' => 'user', 'id' => $u['id']]);
            flash('success', "{$u['email']} is $status.");
        }
    }, 'admin.php', ['tab' => $action === 'account' ? 'accounts' : 'farms']);
}

page_start('Platform admin');
tabs(['farms' => 'Farms', 'accounts' => 'Accounts'], $tab);
if ($tab === 'farms') {
    $farms = rows('SELECT f.*, o.name AS org, u.email AS owner_email, (SELECT COUNT(*) FROM farm_users m WHERE m.farm_id = f.id AND m.status = \'active\') AS members
        FROM farms f JOIN organizations o ON o.id = f.organization_id LEFT JOIN users u ON u.id = o.owner_user_id ORDER BY f.status = \'pending\' DESC, f.name');
    echo '<div class="kpis">' . kpi('Farms', (string) count($farms)) . kpi('Waiting for approval', (string) count(array_filter($farms, fn ($f) => $f['status'] === 'pending')))
        . kpi('Active', (string) count(array_filter($farms, fn ($f) => $f['status'] === 'active'))) . kpi('Accounts', (string) val('SELECT COUNT(*) FROM users')) . '</div><div class="card">';
    table($farms, [
        'Farm' => fn ($f) => '<b>' . e($f['name']) . '</b> <span class="muted">' . e($f['code']) . '</span><div class="muted">' . e($f['district'] ?? '') . '</div>',
        'Owner' => fn ($f) => e($f['org']) . '<div class="muted">' . e($f['owner_email'] ?? '') . '</div>',
        '#Members' => fn ($f) => e($f['members']),
        'Created' => fn ($f) => e(fdate($f['created_at'])),
        'Status' => fn ($f) => badge($f['status']),
        '' => fn ($f) => implode(' ', array_filter([
            $f['status'] === 'pending' ? post_button('Approve', ['action' => 'farm', 'farm_id' => $f['id'], 'status' => 'active'], 'small primary') : null,
            $f['status'] === 'active' ? post_button('Suspend', ['action' => 'farm', 'farm_id' => $f['id'], 'status' => 'suspended', 'reason' => 'admin'], 'small danger', 'Suspend this farm? Its members lose access.') : null,
            $f['status'] === 'suspended' ? post_button('Reactivate', ['action' => 'farm', 'farm_id' => $f['id'], 'status' => 'active'], 'small') : null,
        ])),
    ]);
    echo '</div>';
} else {
    $users = rows('SELECT u.*, (SELECT COUNT(*) FROM farm_users m WHERE m.user_id = u.id) AS farms FROM users u ORDER BY u.user_type, u.name');
    echo '<div class="card">';
    table($users, [
        'Account' => fn ($u) => '<b>' . e($u['name']) . '</b><div class="muted">' . e($u['email']) . '</div>',
        'Type' => fn ($u) => e(label($u['user_type'])),
        '#Farms' => fn ($u) => e($u['farms']),
        'Two-step' => fn ($u) => $u['mfa_enabled_at'] ? '✔' : '—',
        'Last sign-in' => fn ($u) => e(fdate($u['last_login_at'])),
        'Status' => fn ($u) => badge($u['status']) . ($u['locked_until'] && strtotime($u['locked_until'] . ' UTC') > time() ? ' <span class="badge bad">locked</span>' : ''),
        '' => fn ($u) => $u['id'] === $_SESSION['uid'] ? '' : ($u['status'] === 'active'
            ? post_button('Disable', ['action' => 'account', 'user_id' => $u['id'], 'status' => 'disabled'], 'small danger', 'Disable this account?')
            : post_button('Enable', ['action' => 'account', 'user_id' => $u['id'], 'status' => 'active'], 'small')),
    ]);
    echo '</div>';
}
page_end();
