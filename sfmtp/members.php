<?php
/* Members of the farm and their roles. The owner adds people and gives them roles. */
require __DIR__ . '/inc/bootstrap.php';

$farm = require_farm('members.view');
$fid = $farm['id'];
$tab = input_in('tab', ['members', 'roles']) ?? 'members';

if (is_post()) {
    $action = input('action', 20);
    handle(function () use ($action, $fid) {
        $roles = rows('SELECT id, `key` FROM farm_roles WHERE farm_id = ?', [$fid]);
        $roleKeys = array_column($roles, 'key', 'id');
        $picked = array_values(array_filter((array) ($_POST['role_ids'] ?? []), fn ($r) => isset($roleKeys[$r]) && $roleKeys[$r] !== 'owner'));
        $onlyWorkers = !can('members.manage');
        if ($onlyWorkers) {
            require_can('members.invite_workers');
            foreach ($picked as $r) {
                $roleKeys[$r] === 'field_worker' || fail('You may only add field workers.');
            }
        }
        if ($action === 'add') {
            $email = mb_strtolower((string) input('email', 255));
            filter_var($email, FILTER_VALIDATE_EMAIL) || fail('Enter a valid email address.');
            $picked || fail('Choose at least one role.');
            $user = row('SELECT * FROM users WHERE email = ?', [$email]);
            $tempPassword = null;
            if ($user) {
                $user['user_type'] === 'member' || fail('That account cannot join farms.');
                val('SELECT 1 FROM farm_users WHERE farm_id = ? AND user_id = ?', [$fid, $user['id']]) && fail('This person is already a member.');
            } else {
                $name = input('name', 255) ?? fail('Give the person\'s name.');
                $tempPassword = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 12) . random_int(10, 99);
                $user = ['id' => uuid()];
                insert('users', ['id' => $user['id'], 'user_type' => 'member', 'name' => $name, 'email' => $email, 'phone' => input('phone', 32),
                    'password' => password_hash($tempPassword, PASSWORD_DEFAULT), 'status' => 'active', 'email_verified_at' => now_utc(), 'failed_logins' => 0,
                    'created_at' => now_utc(), 'updated_at' => now_utc()]);
            }
            tx(function () use ($fid, $user, $picked) {
                $mid = uuid();
                insert('farm_users', ['id' => $mid, 'farm_id' => $fid, 'user_id' => $user['id'], 'status' => 'active', 'is_owner' => 0, 'invited_by' => $_SESSION['uid'],
                    'joined_at' => now_utc(), 'created_at' => now_utc(), 'updated_at' => now_utc()]);
                foreach ($picked as $r) {
                    insert('farm_user_roles', ['farm_id' => $fid, 'farm_user_id' => $mid, 'farm_role_id' => $r, 'created_at' => now_utc()]);
                }
                audit('access.member.added', null, ['type' => 'farm_user', 'id' => $mid], null, ['user_id' => $user['id'], 'roles' => $picked]);
                notify([$user['id']], 'member_added', 'You were added to ' . current_farm()['name']);
            });
            flash('success', $tempPassword ? "Account created. Give $email this first password (shown once): $tempPassword — they can change it under My account." : 'Member added.');
        } elseif ($action === 'update') {
            require_can('members.manage');
            $m = farm_row('farm_users', input_id('member_id'));
            $m['is_owner'] && fail('The owner\'s membership cannot be changed.');
            $m['user_id'] === $_SESSION['uid'] && fail('You cannot change your own membership.');
            $status = input_in('status', ['active', 'suspended']) ?? 'active';
            $picked || fail('Keep at least one role.');
            tx(function () use ($m, $status, $picked, $fid) {
                q('UPDATE farm_users SET status = ?, updated_at = ? WHERE id = ? AND farm_id = ?', [$status, now_utc(), $m['id'], $fid]);
                q('DELETE FROM farm_user_roles WHERE farm_id = ? AND farm_user_id = ?', [$fid, $m['id']]);
                foreach ($picked as $r) {
                    insert('farm_user_roles', ['farm_id' => $fid, 'farm_user_id' => $m['id'], 'farm_role_id' => $r, 'created_at' => now_utc()]);
                }
                audit('access.member.updated', null, ['type' => 'farm_user', 'id' => $m['id']], null, ['status' => $status, 'roles' => $picked]);
            });
            flash('success', 'Saved.');
        }
    }, 'members.php');
}

page_start('Members');
tabs(['members' => 'Members', 'roles' => 'Roles & permissions'], $tab);
$roles = rows('SELECT r.*, (SELECT COUNT(*) FROM farm_role_permissions p WHERE p.farm_role_id = r.id) AS perms FROM farm_roles r WHERE r.farm_id = ? ORDER BY r.is_system DESC, r.name', [$fid]);

if ($tab === 'members') {
    $members = rows('SELECT fu.*, u.name, u.email, u.phone, u.last_login_at, u.mfa_enabled_at, (SELECT GROUP_CONCAT(fur.farm_role_id) FROM farm_user_roles fur WHERE fur.farm_user_id = fu.id) AS role_ids
        FROM farm_users fu JOIN users u ON u.id = fu.user_id WHERE fu.farm_id = ? ORDER BY fu.is_owner DESC, u.name', [$fid]);
    $roleName = array_column($roles, 'name', 'id');
    echo '<div class="card">';
    table($members, [
        'Member' => fn ($m) => '<b>' . e($m['name']) . '</b>' . ($m['is_owner'] ? ' <span class="badge ok">Owner</span>' : '') . '<div class="muted">' . e($m['email']) . '</div>',
        'Roles' => fn ($m) => e(implode(', ', array_map(fn ($r) => $roleName[$r] ?? '', array_filter(explode(',', (string) $m['role_ids']))))),
        'Two-step' => fn ($m) => $m['mfa_enabled_at'] ? '✔' : '—',
        'Last sign-in' => fn ($m) => e(fdate($m['last_login_at'])),
        'Status' => fn ($m) => badge($m['status']),
        '' => function ($m) use ($roles) {
            if (!can('members.manage') || $m['is_owner'] || $m['user_id'] === $_SESSION['uid']) {
                return '';
            }
            $have = explode(',', (string) $m['role_ids']);
            $h = '<details><summary class="muted">Change</summary><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="update"><input type="hidden" name="member_id" value="' . e($m['id']) . '">';
            foreach ($roles as $r) {
                if ($r['key'] !== 'owner') {
                    $h .= '<label class="row"><input type="checkbox" style="width:auto" name="role_ids[]" value="' . e($r['id']) . '"' . (in_array($r['id'], $have, true) ? ' checked' : '') . '> ' . e($r['name']) . '</label>';
                }
            }
            return $h . '<select name="status">' . enum_options(['active', 'suspended'], $m['status']) . '</select><button class="small">Save</button></form></details>';
        },
    ]);
    echo '</div>';
    if (can('members.manage') || can('members.invite_workers')) {
        form_start('Add a member');
        $choices = array_filter($roles, fn ($r) => $r['key'] !== 'owner' && (can('members.manage') || $r['key'] === 'field_worker'));
        echo '<input type="hidden" name="action" value="add"><div class="fields">' . field('Email', '<input type="email" name="email" required>') . field('Name', '<input name="name">', 'For a new account.')
            . field('Phone', '<input name="phone">') . field('Roles', '<div class="stack">' . implode('', array_map(fn ($r) => '<label class="row"><input type="checkbox" style="width:auto" name="role_ids[]" value="' . e($r['id']) . '"> ' . e($r['name']) . '</label>', $choices)) . '</div>') . '</div>'
            . '<p class="muted">Someone new gets an account with a first password that you hand over. Someone with an account is added straight away.</p>';
        form_end('Add member');
    }
} else {
    $perms = rows('SELECT frp.farm_role_id, p.`key`, p.description, frp.scope FROM farm_role_permissions frp JOIN permissions p ON p.id = frp.permission_id WHERE frp.farm_id = ? ORDER BY p.`key`', [$fid]);
    $byRole = [];
    foreach ($perms as $p) {
        $byRole[$p['farm_role_id']][] = $p;
    }
    foreach ($roles as $r) {
        echo '<details class="card"><summary>' . e($r['name']) . ' <span class="muted">(' . e($r['perms']) . ' permissions)</span></summary><p class="muted">' . e($r['description']) . '</p>';
        table($byRole[$r['id']] ?? [], ['Permission' => fn ($p) => e($p['description']) . ' <span class="muted code">' . e($p['key']) . '</span>', 'Scope' => fn ($p) => e(label($p['scope']))]);
        echo '</details>';
    }
}
page_end();
