<?php
require __DIR__ . '/inc/bootstrap.php';

$user = require_login();
if (($next = $_SESSION['after_login'] ?? null) && preg_match('/^invite\.php\?token=[0-9a-f]{48}$/', $next)) {
    unset($_SESSION['after_login']);
    redirect($next);
}
if (current_farm()) {
    redirect('dashboard.php');
}
if (is_platform_admin($user)) {
    redirect('admin.php');
}
redirect($user['user_type'] === 'party' || portal_links() ? 'portal.php' : 'farms.php');
