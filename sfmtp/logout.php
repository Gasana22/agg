<?php
require __DIR__ . '/inc/bootstrap.php';

if (is_post()) {
    verify_csrf();
    if (current_user()) {
        audit('auth.logout', null, ['type' => 'user', 'id' => current_user()['id']]);
    }
    logout();
}
redirect('login.php');
