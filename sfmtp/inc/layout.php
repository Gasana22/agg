<?php
/* Page frame: header, farm switcher, menu by permission, flash messages. */

function nav_items(): array
{
    if (!empty($GLOBALS['sfmtp_portal'])) {
        return array_map(fn ($i) => [$i[0], $i[1], null], portal_nav());
    }
    $portals = portal_links() ? [['portal.php', 'Supplier & customer portals', null]] : [];
    if (!current_farm()) {
        return is_platform_admin() ? [['admin.php', 'Platform admin', null]] : [...(current_user()['user_type'] === 'party' ? [] : [['farms.php', 'My farms', null]]), ...$portals];
    }
    $items = [
        ['dashboard.php', 'Dashboard', null],
        ['tasks.php', 'Tasks', 'tasks.view'],
        ['structure.php', 'Farm map', 'structure.view'],
        ['crops.php', 'Crops', 'crops.operations.view'],
        ['livestock.php', 'Livestock', 'livestock.animals.view'],
        ['inventory.php', 'Inventory', 'inventory.view'],
        ['purchasing.php', 'Purchasing', 'suppliers.view'],
        ['workers.php', 'Workers', 'workers.view'],
        ['finance.php', 'Finance', 'finance.view'],
        ['sales.php', 'Sales', 'sales.view'],
        ['trace.php', 'Traceability', 'trace.batches.view'],
        ['members.php', 'Members', 'members.view'],
        ['audit-log.php', 'Audit log', 'audit.view'],
        ['settings.php', 'Farm settings', 'farm.settings.manage'],
    ];
    $items = array_values(array_filter($items, fn ($i) => $i[2] === null || can($i[2])));
    if (can('suppliers.manage') || can('customers.manage')) {
        array_splice($items, count($items) - (can('farm.settings.manage') ? 1 : 0), 0, [['portal-access.php', 'Portal access', null]]);
    }
    return [...$items, ...$portals];
}

function page_start(string $title, bool $bare = false): void
{
    $_SESSION['old_view'] = $_SESSION['old'] ?? [];
    unset($_SESSION['old']);
    $user = current_user();
    if ($user && $user['user_type'] === 'party') {
        $GLOBALS['sfmtp_portal'] = true;
    }
    $portal = !empty($GLOBALS['sfmtp_portal']);
    if ($portal) {
        act_in_farm(null);
    }
    $farm = $user && ($_SESSION['mfa_ok'] ?? false) && !$portal ? current_farm() : null;
    $self = basename($_SERVER['SCRIPT_NAME'] ?? '') . (($_GET['tab'] ?? null) && $portal ? '?tab=' . $_GET['tab'] : '');
    $appName = e(config('app_name', 'SFMTP'));
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' · ' . $appName . '</title><link rel="stylesheet" href="' . e(url('assets/style.css')) . '"></head><body>';

    if ($bare || !$user || empty($_SESSION['mfa_ok'])) {
        echo '<main class="bare"><div class="brand-lg">🌱 ' . $appName . '</div>';
        foreach (take_flashes() as [$type, $msg]) {
            echo '<div class="flash ' . e($type) . '">' . e($msg) . '</div>';
        }
        return;
    }

    $unread = $farm ? (int) val('SELECT COUNT(*) FROM member_notifications WHERE farm_id = ? AND user_id = ? AND read_at IS NULL', [$farm['id'], $user['id']]) : 0;
    echo '<header class="top"><a class="brand" href="' . e(url($portal ? 'portal.php' : 'index.php')) . '">🌱 ' . $appName . ($portal ? ' <span class="muted">portal</span>' : '') . '</a>';
    $farms = $portal ? [] : my_farms();
    if ($farms) {
        echo '<form method="post" action="' . e(url('farms.php')) . '" class="switcher">' . csrf_field() . '<select name="farm_id" data-autosubmit aria-label="Farm">';
        foreach ($farms as $f) {
            echo '<option value="' . e($f['id']) . '"' . ($farm && $farm['id'] === $f['id'] ? ' selected' : '') . '>' . e($f['name']) . '</option>';
        }
        echo '</select><noscript><button>Go</button></noscript></form>';
    }
    echo '<div class="user">' . ($farm ? '<a href="' . e(url('notifications.php')) . '" title="Notifications">🔔' . ($unread ? '<span class="dot">' . $unread . '</span>' : '') . '</a>' : '')
        . ($portal && my_farms() ? '<a href="' . e(url('dashboard.php')) . '">My farms</a>' : '');
    echo '<a href="' . e(url('profile.php')) . '">' . e($user['name']) . '</a>';
    echo '<form method="post" action="' . e(url('logout.php')) . '">' . csrf_field() . '<button class="link">Sign out</button></form></div></header>';

    echo '<div class="shell"><nav class="side"><input type="checkbox" id="navtoggle"><label for="navtoggle" class="navbtn">☰ Menu</label><ul>';
    foreach (nav_items() as [$href, $text]) {
        echo '<li><a href="' . e(url($href)) . '"' . ($self === $href ? ' class="on"' : '') . '>' . e($text) . '</a></li>';
    }
    if (is_platform_admin()) {
        echo '<li><a href="' . e(url('admin.php')) . '">Platform admin</a></li>';
    }
    echo '</ul></nav><main class="content">';
    if ($farm && $farm['status'] === 'pending') {
        echo '<div class="flash warn">This farm is waiting for approval by the platform team. You can set it up meanwhile.</div>';
    }
    foreach (take_flashes() as [$type, $msg]) {
        echo '<div class="flash ' . e($type) . '">' . e($msg) . '</div>';
    }
    echo '<h1>' . e($title) . '</h1>';
}

function page_end(): void
{
    $user = current_user();
    echo ($user && !empty($_SESSION['mfa_ok'])) ? '</main></div>' : '</main>';
    echo '<script src="' . e(url('assets/app.js')) . '"></script></body></html>';
}

/** Tabs within a page: [key => label]. */
function tabs(array $tabs, string $current, array $extraQuery = []): void
{
    echo '<div class="tabs">';
    foreach ($tabs as $key => $text) {
        echo '<a href="?' . e(http_build_query(['tab' => $key] + $extraQuery)) . '"' . ($key === $current ? ' class="on"' : '') . '>' . e($text) . '</a>';
    }
    echo '</div>';
}

/** A simple table: $cols = [header => fn(row) => html]. Cells are HTML: escape inside the callbacks. */
function table(array $rows, array $cols, string $empty = 'Nothing here yet.'): void
{
    if (!$rows) {
        echo '<p class="muted empty">' . e($empty) . '</p>';
        return;
    }
    echo '<div class="tablewrap"><table><thead><tr>';
    foreach (array_keys($cols) as $h) {
        $num = str_starts_with($h, '#');
        echo '<th' . ($num ? ' class="num"' : '') . '>' . e(ltrim($h, '#')) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($cols as $h => $fn) {
            echo '<td' . (str_starts_with($h, '#') ? ' class="num"' : '') . '>' . $fn($r) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

/** A collapsible form card. */
function form_start(string $title, string $action = '', bool $open = false): void
{
    echo '<details class="card form"' . ($open ? ' open' : '') . '><summary>' . e($title) . '</summary><form method="post" action="' . e($action) . '">' . csrf_field();
}

function form_end(string $button = 'Save'): void
{
    echo '<div class="actions"><button class="primary">' . e($button) . '</button></div></form></details>';
}

function field(string $label, string $inner, ?string $hint = null): string
{
    return '<label class="field"><span>' . e($label) . '</span>' . $inner . ($hint ? '<small>' . e($hint) . '</small>' : '') . '</label>';
}

function kpi(string $label, string $value, ?string $hint = null, ?string $href = null): string
{
    $inner = '<div class="kpi-label">' . e($label) . '</div><div class="kpi-value">' . $value . '</div>' . ($hint ? '<div class="kpi-hint">' . e($hint) . '</div>' : '');
    return $href ? '<a class="kpi" href="' . e(url($href)) . '">' . $inner . '</a>' : '<div class="kpi">' . $inner . '</div>';
}

/** A small inline POST button (for state changes). */
function post_button(string $text, array $fields, string $class = '', ?string $confirm = null, string $action = ''): string
{
    $h = '<form method="post" class="inline" action="' . e($action) . '"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field();
    foreach ($fields as $k => $v) {
        $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    }
    return $h . '<button class="' . e($class) . '">' . e($text) . '</button></form>';
}
