<?php
require __DIR__ . '/inc/bootstrap.php';

$user = require_login();
if (current_farm()) {
    redirect('dashboard.php');
}
redirect(is_platform_admin($user) ? 'admin.php' : 'farms.php');
