<?php
/* Choose a new password from an emailed reset link. The link works once, for 60 minutes. */
require __DIR__ . '/inc/bootstrap.php';

$email = mb_strtolower(trim((string) ($_GET['email'] ?? $_POST['email'] ?? '')));
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$saved = preg_match('/^[0-9a-f]{64}$/', $token) ? row('SELECT * FROM password_reset_tokens WHERE email = ?', [$email]) : null;
$valid = $saved && hash_equals($saved['token'], hash('sha256', $token)) && strtotime($saved['created_at'] . ' UTC') > time() - 3600;

if (is_post() && $valid) {
    verify_csrf();
    $new = (string) ($_POST['password'] ?? '');
    if (strlen($new) < 10 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
        flash('error', 'The password needs at least 10 characters, with letters and numbers.');
    } elseif ($new !== (string) ($_POST['confirm'] ?? '')) {
        flash('error', 'The two passwords are different.');
    } else {
        $user = row('SELECT id FROM users WHERE email = ?', [$email]);
        tx(function () use ($user, $new, $email) {
            q('UPDATE users SET password = ?, failed_logins = 0, locked_until = NULL, updated_at = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), now_utc(), $user['id']]);
            q('DELETE FROM password_reset_tokens WHERE email = ?', [$email]);
            audit('auth.password_reset', null, ['type' => 'user', 'id' => $user['id']], null, null, $user['id']);
        });
        flash('success', 'Password changed. Sign in with the new one.');
        redirect('login.php');
    }
    redirect('reset.php?' . http_build_query(['email' => $email, 'token' => $token]));
}

page_start('New password', true);
if (!$valid) {
    echo '<div class="card"><h2>This link does not work</h2><p>It may be older than 60 minutes or already used. <a href="' . e(url('forgot.php')) . '">Ask for a new one</a>.</p></div>';
} else {
    echo '<div class="card"><h2>Choose a new password</h2><p class="muted">For ' . e($email) . '</p><form method="post">' . csrf_field()
        . '<input type="hidden" name="email" value="' . e($email) . '"><input type="hidden" name="token" value="' . e($token) . '"><div class="stack">'
        . field('New password', '<input type="password" name="password" required minlength="10" autocomplete="new-password">', 'At least 10 characters, with letters and numbers.')
        . field('Again', '<input type="password" name="confirm" required autocomplete="new-password">') . '</div><div class="actions"><button class="primary">Save password</button></div></form></div>';
}
page_end();
