<?php
/*
 * The current farm and what the member may do on it.
 *
 * A member only ever works inside a farm they belong to (active membership,
 * farm not suspended or closed). Every farm query filters by farm_id(), and
 * permissions come from the member's roles (farm_role_permissions).
 */

/** Active memberships of the signed-in user, with farm details. */
function my_farms(): array
{
    $user = current_user();
    if (!$user) {
        return [];
    }
    return rows("SELECT f.*, fu.id AS membership_id, fu.is_owner FROM farm_users fu JOIN farms f ON f.id = fu.farm_id
        WHERE fu.user_id = ? AND fu.status = 'active' AND f.status IN ('pending','active') ORDER BY f.name", [$user['id']]);
}

function current_farm(): ?array
{
    static $farm = false;
    if ($farm !== false) {
        return $farm;
    }
    $farm = null;
    $id = $_SESSION['farm_id'] ?? null;
    foreach (my_farms() as $f) {
        if ($id === null || $f['id'] === $id) {
            $farm = $f;
            break;
        }
    }
    if ($farm) {
        $_SESSION['farm_id'] = $farm['id'];
    }
    return $farm;
}

function farm_id(): string
{
    $f = current_farm();
    if (!$f) {
        throw new RuntimeException('No farm selected.');
    }
    return $f['id'];
}

/** Pages with farm data call this: signed in, and a member of the current farm. */
function require_farm(?string $permission = null): array
{
    $user = require_login();
    $farm = current_farm();
    if (!$farm) {
        redirect(is_platform_admin($user) ? 'admin.php' : 'farms.php');
    }
    if ($permission !== null) {
        require_can($permission);
    }
    return $farm;
}

/** permission key => scope (all / assigned / own) for the member on this farm. */
function my_permissions(): array
{
    static $perms = null;
    if ($perms !== null) {
        return $perms;
    }
    $perms = [];
    $farm = current_farm();
    if (!$farm) {
        return $perms;
    }
    $rank = ['own' => 1, 'assigned' => 2, 'all' => 3];
    $list = rows('SELECT p.`key`, frp.scope FROM farm_user_roles fur
        JOIN farm_role_permissions frp ON frp.farm_role_id = fur.farm_role_id
        JOIN permissions p ON p.id = frp.permission_id
        WHERE fur.farm_user_id = ? AND fur.farm_id = ?', [$farm['membership_id'], $farm['id']]);
    foreach ($list as $p) {
        if (!isset($perms[$p['key']]) || $rank[$p['scope']] > $rank[$perms[$p['key']]]) {
            $perms[$p['key']] = $p['scope'];
        }
    }
    return $perms;
}

function can(string $permission): bool
{
    return isset(my_permissions()[$permission]);
}

/** The scope of a permission: 'all', 'assigned', 'own' or null. */
function scope(string $permission): ?string
{
    return my_permissions()[$permission] ?? null;
}

function require_can(string $permission): void
{
    if (!can($permission)) {
        http_response_code(403);
        page_start('Not allowed');
        echo '<div class="card"><h2>Not allowed</h2><p>Your role on this farm does not include this page. Ask the farm owner if you need it.</p></div>';
        page_end();
        exit;
    }
}

function is_owner(): bool
{
    return (bool) (current_farm()['is_owner'] ?? false);
}

/** The worker record linked to the signed-in member on this farm, if any. */
function my_worker(): ?array
{
    $farm = current_farm();
    return $farm ? row('SELECT * FROM workers WHERE farm_id = ? AND farm_user_id = ?', [$farm['id'], $farm['membership_id']]) : null;
}

function farm_settings(): array
{
    $json = val('SELECT settings FROM farm_settings WHERE farm_id = ?', [farm_id()]);
    return json_decode((string) $json, true) ?: [];
}

/** A record of this farm, or a 404 page. */
function farm_row(string $table, ?string $id): array
{
    $r = $id ? row("SELECT * FROM `$table` WHERE id = ? AND farm_id = ?", [$id, farm_id()]) : null;
    if (!$r) {
        http_response_code(404);
        page_start('Not found');
        echo '<div class="card"><h2>Not found</h2><p>This record does not exist on this farm.</p></div>';
        page_end();
        exit;
    }
    return $r;
}

/** Check that an id submitted in a form belongs to this farm. */
function belongs(string $table, ?string $id): bool
{
    return $id !== null && (bool) val("SELECT 1 FROM `$table` WHERE id = ? AND farm_id = ?", [$id, farm_id()]);
}

function notify(array $userIds, string $kind, string $title, ?string $body = null, ?string $link = null): void
{
    foreach (array_unique(array_filter($userIds)) as $uid) {
        insert('member_notifications', ['id' => uuid(), 'farm_id' => farm_id(), 'user_id' => $uid, 'kind' => $kind, 'title' => mb_substr($title, 0, 150),
            'body' => $body ? mb_substr($body, 0, 500) : null, 'link' => $link, 'created_at' => gmdate('Y-m-d H:i:s.u')]);
    }
}

/** Create the chart of accounts the first time a farm uses finance. */
function ensure_chart(): void
{
    if (val('SELECT 1 FROM ledger_accounts WHERE farm_id = ? LIMIT 1', [farm_id()])) {
        return;
    }
    foreach (DEFAULT_CHART as $a) {
        insert('ledger_accounts', ['id' => uuid(), 'farm_id' => farm_id(), 'code' => $a['code'], 'name' => $a['name'], 'description' => $a['description'],
            'type' => $a['type'], 'is_cash' => $a['is_cash'], 'is_system' => $a['is_system'], 'is_active' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
    }
}

/** A new farm owned by $user: pending until the platform approves it. */
function create_farm(array $user, array $data): string
{
    return tx(function () use ($user, $data) {
        $orgId = val('SELECT id FROM organizations WHERE owner_user_id = ?', [$user['id']]);
        if (!$orgId) {
            $orgId = uuid();
            insert('organizations', ['id' => $orgId, 'name' => $data['organization_name'] ?: $user['name'], 'owner_user_id' => $user['id'], 'status' => 'active',
                'created_at' => now_utc(), 'updated_at' => now_utc()]);
        }
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $data['name']) ?: 'FRM', 0, 3));
        do {
            $code = str_pad($prefix, 3, 'X') . '-' . random_int(1000, 9999);
        } while (val('SELECT 1 FROM farms WHERE code = ?', [$code]));

        $farmId = uuid();
        insert('farms', ['id' => $farmId, 'organization_id' => $orgId, 'code' => $code, 'name' => $data['name'], 'status' => 'pending',
            'district' => $data['district'], 'village' => $data['village'], 'country' => 'UG', 'size_ha' => $data['size_ha'],
            'timezone' => 'Africa/Kampala', 'currency' => 'UGX', 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        insert('farm_settings', ['farm_id' => $farmId, 'settings' => json_encode(DEFAULT_FARM_SETTINGS), 'created_at' => now_utc(), 'updated_at' => now_utc()]);

        $permIds = [];
        foreach (rows('SELECT id, `key` FROM permissions') as $p) {
            $permIds[$p['key']] = $p['id'];
        }
        $roleIds = [];
        foreach (ROLE_TEMPLATES as $key => $tpl) {
            $roleIds[$key] = uuid();
            insert('farm_roles', ['id' => $roleIds[$key], 'farm_id' => $farmId, 'key' => $key, 'name' => $tpl['name'], 'description' => $tpl['description'],
                'is_system' => 1, 'is_locked' => $key === 'owner' ? 1 : 0, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
            foreach ($tpl['grants'] as $perm => $scope) {
                if (isset($permIds[$perm])) {
                    insert('farm_role_permissions', ['farm_id' => $farmId, 'farm_role_id' => $roleIds[$key], 'permission_id' => $permIds[$perm], 'scope' => $scope]);
                }
            }
        }
        $membership = uuid();
        insert('farm_users', ['id' => $membership, 'farm_id' => $farmId, 'user_id' => $user['id'], 'status' => 'active', 'is_owner' => 1,
            'joined_at' => now_utc(), 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        insert('farm_user_roles', ['farm_id' => $farmId, 'farm_user_id' => $membership, 'farm_role_id' => $roleIds['owner'], 'created_at' => now_utc()]);
        audit('farm.created', $farmId, ['type' => 'farm', 'id' => $farmId], null, ['name' => $data['name'], 'code' => $code]);
        return $farmId;
    });
}
