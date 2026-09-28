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
$tab = input_in('tab', ['farms', 'accounts', 'integrations', 'messages']) ?? 'farms';

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
        } elseif ($action === 'provider') {
            $kind = input_in('kind', array_keys(PROVIDER_DRIVERS)) ?? fail('Choose email, SMS or payments.');
            $driver = input_in('provider', array_keys(PROVIDER_DRIVERS[$kind])) ?? fail('Choose the service.');
            $existing = input_id('provider_id') ? row('SELECT * FROM integration_providers WHERE id = ?', [input_id('provider_id')]) : null;
            $old = $existing ? provider_config($existing) : [];
            $cfg = [];
            foreach (PROVIDER_DRIVERS[$kind][$driver][1] as $field => $labelText) {
                $v = input('cfg_' . $field, 300);
                // Secret fields left empty keep their saved value.
                $cfg[$field] = $v ?? (str_starts_with($labelText, '*') ? ($old[$field] ?? null) : null);
            }
            $name = input('name', 100) ?? PROVIDER_DRIVERS[$kind][$driver][0];
            $data = ['kind' => $kind, 'provider' => $driver, 'name' => $name, 'config' => seal(json_encode($cfg)), 'priority' => (int) (input_num('priority') ?? 100), 'updated_at' => now_utc()];
            if ($existing) {
                q('UPDATE integration_providers SET kind = ?, provider = ?, name = ?, config = ?, priority = ?, updated_at = ?, consecutive_failures = 0 WHERE id = ?', [...array_values($data), $existing['id']]);
            } else {
                insert('integration_providers', $data + ['id' => uuid(), 'is_enabled' => 1, 'is_default' => has_provider($kind) ? 0 : 1, 'created_at' => now_utc()]);
            }
            audit('platform.integration.saved', null, ['type' => 'integration_provider', 'id' => $existing['id'] ?? null], null, ['kind' => $kind, 'provider' => $driver]);
            flash('success', "$name saved.");
        } elseif ($action === 'provider_state') {
            $p = row('SELECT * FROM integration_providers WHERE id = ?', [input_id('provider_id')]) ?? fail('Unknown provider.');
            $what = input_in('what', ['enable', 'disable', 'default', 'delete']) ?? fail('Unknown change.');
            match ($what) {
                'enable' => q('UPDATE integration_providers SET is_enabled = 1, consecutive_failures = 0 WHERE id = ?', [$p['id']]),
                'disable' => q('UPDATE integration_providers SET is_enabled = 0 WHERE id = ?', [$p['id']]),
                'default' => q('UPDATE integration_providers SET is_default = (id = ?) WHERE kind = ?', [$p['id'], $p['kind']]),
                'delete' => val('SELECT 1 FROM online_payments WHERE provider_id = ?', [$p['id']])
                    ? fail('This provider has payments on record: disable it instead.') : q('DELETE FROM integration_providers WHERE id = ?', [$p['id']]),
            };
            audit('platform.integration.' . $what, null, ['type' => 'integration_provider', 'id' => $p['id']]);
            flash('success', "{$p['name']}: done.");
        } elseif ($action === 'provider_test') {
            $to = input('to', 150) ?? fail('Give an address or phone number to test with.');
            $kind = input_in('kind', ['email', 'sms']) ?? fail('Choose email or SMS.');
            $ok = $kind === 'email'
                ? send_email($to, config('app_name', 'SFMTP') . ' test email', "This is a test message from the platform admin page.\n", 'test')
                : send_sms($to, config('app_name', 'SFMTP') . ' test SMS', 'test');
            $last = row('SELECT status, provider, error FROM message_outbox WHERE channel = ? AND purpose = \'test\' ORDER BY id DESC LIMIT 1', [$kind]);
            $ok ? flash('success', 'Test ' . $last['status'] . ' through ' . $last['provider'] . '.') : fail('The test failed: ' . ($last['error'] ?? 'unknown error'));
        } elseif ($action === 'account') {
            $u = row('SELECT * FROM users WHERE id = ?', [input_id('user_id')]) ?? fail('Unknown account.');
            $u['id'] === $_SESSION['uid'] && fail('You cannot change your own account here.');
            $status = input_in('status', ['active', 'disabled']) ?? fail('Choose the status.');
            q('UPDATE users SET status = ?, failed_logins = 0, locked_until = NULL, updated_at = ? WHERE id = ?', [$status, now_utc(), $u['id']]);
            audit('platform.account.' . $status, null, ['type' => 'user', 'id' => $u['id']]);
            flash('success', "{$u['email']} is $status.");
        }
    }, 'admin.php', ['tab' => match ($action) { 'account' => 'accounts', 'provider', 'provider_state', 'provider_test' => 'integrations', default => 'farms' }]);
}

page_start('Platform admin');
tabs(['farms' => 'Farms', 'accounts' => 'Accounts', 'integrations' => 'Integrations', 'messages' => 'Messages sent'], $tab);
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
} elseif ($tab === 'integrations') {
    $list = rows('SELECT * FROM integration_providers ORDER BY kind, is_default DESC, priority, name');
    echo '<div class="card"><p class="muted">Email and SMS carry notifications, invitations and password resets. Payments let customers pay invoices online. Keys are stored encrypted.</p>';
    table($list, [
        'Service' => fn ($p) => '<b>' . e($p['name']) . '</b> <span class="muted">' . e(PROVIDER_DRIVERS[$p['kind']][$p['provider']][0] ?? $p['provider']) . '</span>' . ($p['is_default'] ? ' <span class="badge ok">default</span>' : ''),
        'For' => fn ($p) => e(label($p['kind'])),
        'Health' => fn ($p) => ($p['last_success_at'] ? 'Last worked ' . e(fdate($p['last_success_at'], true)) : '<span class="muted">Not used yet</span>')
            . ($p['last_error'] ? '<div class="muted">' . e($p['consecutive_failures']) . ' failure(s): ' . e($p['last_error']) . '</div>' : ''),
        'Status' => fn ($p) => badge($p['is_enabled'] ? 'active' : 'disabled'),
        '' => function ($p) {
            $cfg = provider_config($p);
            $h = '<details><summary class="muted">Change</summary><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="provider"><input type="hidden" name="provider_id" value="' . e($p['id']) . '">'
                . '<input type="hidden" name="kind" value="' . e($p['kind']) . '"><input type="hidden" name="provider" value="' . e($p['provider']) . '"><div class="stack">'
                . field('Name', '<input name="name" value="' . e($p['name']) . '">') . field('Priority (lower first)', '<input name="priority" inputmode="numeric" value="' . e($p['priority']) . '">');
            foreach (PROVIDER_DRIVERS[$p['kind']][$p['provider']][1] ?? [] as $f => $l) {
                $secret = str_starts_with($l, '*');
                $h .= field(ltrim($l, '*'), '<input name="cfg_' . e($f) . '"' . ($secret ? ' type="password" placeholder="' . (!empty($cfg[$f]) ? 'saved; leave empty to keep' : '') . '"' : ' value="' . e($cfg[$f] ?? '') . '"') . ' autocomplete="off">');
            }
            $h .= '</div><button class="small">Save</button></form></details>';
            foreach (($p['is_enabled'] ? ['disable' => 'Disable'] : ['enable' => 'Enable']) + ($p['is_default'] ? [] : ['default' => 'Make default']) + ['delete' => 'Remove'] as $w => $t) {
                $h .= post_button($t, ['action' => 'provider_state', 'provider_id' => $p['id'], 'what' => $w], 'small' . ($w === 'delete' ? ' danger' : ''), $w === 'delete' ? 'Remove this provider?' : null);
            }
            return $h;
        },
    ], 'No services set up yet: email, SMS and online payment are off.');
    echo '</div><div class="grid">';
    foreach (PROVIDER_DRIVERS as $kind => $drivers) {
        foreach ($drivers as $d => [$title, $fields]) {
            form_start('Add ' . $kind . ': ' . $title);
            echo '<input type="hidden" name="action" value="provider"><input type="hidden" name="kind" value="' . e($kind) . '"><input type="hidden" name="provider" value="' . e($d) . '"><div class="stack">'
                . field('Name', '<input name="name" value="' . e($title) . '">');
            foreach ($fields as $f => $l) {
                echo field(ltrim($l, '*'), '<input name="cfg_' . e($f) . '"' . (str_starts_with($l, '*') ? ' type="password"' : '') . ' autocomplete="off">');
            }
            echo '</div>';
            if ($d === 'flutterwave') {
                echo '<p class="muted">In the Flutterwave dashboard set the webhook to ' . e(rtrim((string) config('app_url'), '/')) . '/pay-webhook.php with the same secret hash.</p>';
            }
            form_end('Add');
        }
    }
    form_start('Send a test message');
    echo '<input type="hidden" name="action" value="provider_test"><div class="fields">' . field('By', '<select name="kind"><option value="email">Email</option><option value="sms">SMS</option></select>')
        . field('To', '<input name="to" required placeholder="you@example.com or +2567…">') . '</div>';
    form_end('Send test');
    echo '</div>';
} elseif ($tab === 'messages') {
    $msgs = rows('SELECT m.*, f.name AS farm FROM message_outbox m LEFT JOIN farms f ON f.id = m.farm_id ORDER BY m.created_at DESC LIMIT 200');
    echo '<div class="card">';
    table($msgs, ['When' => fn ($m) => e(fdate($m['created_at'], true)), 'By' => fn ($m) => e(label($m['channel'])), 'To' => fn ($m) => e($m['recipient']),
        'Message' => fn ($m) => '<b>' . e($m['subject'] ?? '') . '</b> <span class="muted">' . e(mb_substr($m['body'], 0, 120)) . '</span>', 'Why' => fn ($m) => e(label($m['purpose'])),
        'Farm' => fn ($m) => e($m['farm'] ?? '—'), 'Result' => fn ($m) => badge($m['status'] === 'logged' ? 'recorded' : $m['status']) . ($m['error'] ? '<div class="muted">' . e($m['error']) . '</div>' : '') . ' <span class="muted">' . e($m['provider'] ?? '') . '</span>'],
        'Nothing sent yet.');
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
