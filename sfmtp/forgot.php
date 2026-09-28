<?php
/* Forgotten password: email a one-time link (valid 60 minutes). The answer is the same whether or not the account exists. */
require __DIR__ . '/inc/bootstrap.php';

if (current_user() && !empty($_SESSION['mfa_ok'])) {
    redirect('index.php');
}

if (is_post()) {
    verify_csrf();
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    // At most 5 requests an hour from one address.
    $key = 'reset:' . hash('sha256', client_ip());
    q('INSERT INTO cache (`key`, value, expiration) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE value = value + 1', [$key, time() + 3600]);
    $user = filter_var($email, FILTER_VALIDATE_EMAIL) && (int) val('SELECT value FROM cache WHERE `key` = ?', [$key]) <= 5
        ? row("SELECT * FROM users WHERE email = ? AND status = 'active'", [$email]) : null;
    $recent = $user ? val('SELECT created_at FROM password_reset_tokens WHERE email = ?', [$email]) : null;
    if ($user && (!$recent || strtotime($recent . ' UTC') < time() - 120)) {
        $token = bin2hex(random_bytes(32));
        q('REPLACE INTO password_reset_tokens (email, token, created_at) VALUES (?, ?, ?)', [$email, hash('sha256', $token), now_utc()]);
        $link = rtrim((string) config('app_url'), '/') . '/reset.php?' . http_build_query(['email' => $email, 'token' => $token]);
        send_email($email, 'Reset your ' . config('app_name', 'SFMTP') . ' password', "Hello {$user['name']},\n\nSomeone asked to reset the password of this account. If it was you, open this link within 60 minutes:\n\n$link\n\nIf it was not you, ignore this email: your password stays the same.\n", 'password_reset');
        audit('auth.password_reset_requested', null, ['type' => 'user', 'id' => $user['id']], null, null, $user['id']);
    }
    flash('success', 'If that address has an account, a link to reset the password is on its way. Check your email.');
    redirect('login.php');
}

page_start('Forgotten password', true);
?>
<div class="card"><h2>Forgotten password</h2>
<p class="muted">Enter your email address and we send you a link to choose a new password.</p>
<form method="post"><?= csrf_field() ?><div class="stack"><?= field('Email', '<input type="email" name="email" required autocomplete="username">') ?></div>
<div class="actions"><button class="primary">Send the link</button></div></form></div>
<p><a href="<?= e(url('login.php')) ?>">← Sign in</a></p>
<?php page_end();
