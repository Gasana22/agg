<?php
/* Second sign-in step: a code from the authenticator app, or a recovery code. */
require __DIR__ . '/inc/bootstrap.php';

$user = current_user();
if (!$user) {
    redirect('login.php');
}
if (!empty($_SESSION['mfa_ok'])) {
    redirect('index.php');
}
if (!user_has_mfa($user['id'])) {
    redirect('mfa-setup.php');
}

if (is_post()) {
    verify_csrf();
    $_SESSION['mfa_tries'] = ($_SESSION['mfa_tries'] ?? 0) + 1;
    if ($_SESSION['mfa_tries'] > 5) {
        logout();
        session_start();
        flash('error', 'Too many wrong codes. Sign in again.');
        redirect('login.php');
    }
    if (verify_second_step($user['id'], (string) ($_POST['code'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['mfa_ok'] = true;
        unset($_SESSION['mfa_tries']);
        redirect('index.php');
    }
    flash('error', 'That code is not right. Use the current 6-digit code, or one of your recovery codes.');
    redirect('mfa.php');
}

page_start('Two-step sign-in', true);
?>
<div class="card">
  <h2>Two-step sign-in</h2>
  <p class="muted">Enter the 6-digit code from your authenticator app for <?= e($user['email']) ?>.</p>
  <form method="post">
    <?= csrf_field() ?>
    <?= field('Code', '<input name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus>', 'Lost your phone? Enter one of your recovery codes instead.') ?>
    <div class="actions"><button class="primary">Continue</button></div>
  </form>
</div>
<form method="post" action="logout.php"><?= csrf_field() ?><button class="link">Use another account</button></form>
<?php page_end();
