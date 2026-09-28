<?php
require __DIR__ . '/inc/bootstrap.php';

if (current_user() && !empty($_SESSION['mfa_ok'])) {
    redirect('index.php');
}

if (is_post()) {
    verify_csrf();
    $result = attempt_login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
    if (is_string($result)) {
        flash('error', $result);
        $_SESSION['old'] = ['email' => $_POST['email'] ?? ''];
        redirect('login.php');
    }
    $next = $_SESSION['after_login'] ?? null;
    start_session_for($result);
    if (is_string($next) && preg_match('/^invite\.php\?token=[0-9a-f]{48}$/', $next)) {
        $_SESSION['after_login'] = $next;
    }
    if (empty($_SESSION['mfa_ok'])) {
        redirect(user_has_mfa($result['id']) ? 'mfa.php' : 'mfa-setup.php');
    }
    redirect('index.php');
}

page_start('Sign in', true);
?>
<div class="card">
  <h2>Sign in</h2>
  <form method="post">
    <?= csrf_field() ?>
    <div class="stack">
      <?= field('Email', '<input type="email" name="email" required autocomplete="username" value="' . old('email') . '">') ?>
      <?= field('Password', '<input type="password" name="password" required autocomplete="current-password">') ?>
    </div>
    <div class="actions"><button class="primary">Sign in</button></div>
  </form>
</div>
<?php if (has_provider('email')): ?>
<p><a href="<?= e(url('forgot.php')) ?>">Forgotten your password?</a></p>
<?php else: ?>
<p class="muted">Forgotten your password? Ask the farm owner or the platform team to reset it.</p>
<?php endif ?>
<?php page_end();
