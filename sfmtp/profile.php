<?php
/* Your account: name, phone, password and two-step sign-in. */
require __DIR__ . '/inc/bootstrap.php';

$user = require_login();

if (is_post()) {
    $action = input('action', 20);
    handle(function () use ($user, $action) {
        if ($action === 'profile') {
            $name = input('name', 255) ?? fail('Your name is required.');
            $phone = input('phone', 32);
            if ($phone !== null && !preg_match('/^\+?[0-9 ]{7,20}$/', $phone)) {
                fail('Enter the phone number with digits only, e.g. +256 772 123456.');
            }
            $channels = json_encode(['email' => (bool) input('notify_email'), 'sms' => (bool) input('notify_sms')]);
            q('UPDATE users SET name = ?, phone = ?, notification_channels = ?, updated_at = ? WHERE id = ?', [$name, $phone, $channels, now_utc(), $user['id']]);
            flash('success', 'Saved.');
        } elseif ($action === 'password') {
            if (!password_verify((string) ($_POST['current'] ?? ''), $user['password'])) {
                fail('Your current password is not right.');
            }
            $new = (string) ($_POST['new'] ?? '');
            if (strlen($new) < 10 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
                fail('The new password needs at least 10 characters, with letters and numbers.');
            }
            if ($new !== (string) ($_POST['confirm'] ?? '')) {
                fail('The two new passwords are different.');
            }
            q('UPDATE users SET password = ?, updated_at = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), now_utc(), $user['id']]);
            session_regenerate_id(true);
            audit('auth.password_changed', null, ['type' => 'user', 'id' => $user['id']]);
            flash('success', 'Password changed.');
        } elseif ($action === 'mfa_off') {
            if (mfa_required($user)) {
                fail('Your role needs two-step sign-in, so it cannot be turned off.');
            }
            q("DELETE FROM user_mfa_factors WHERE user_id = ?", [$user['id']]);
            q('DELETE FROM mfa_recovery_codes WHERE user_id = ?', [$user['id']]);
            q('UPDATE users SET mfa_enabled_at = NULL WHERE id = ?', [$user['id']]);
            audit('auth.mfa_disabled', null, ['type' => 'user', 'id' => $user['id']]);
            flash('success', 'Two-step sign-in is off.');
        }
    }, 'profile.php');
}

page_start('My account');
$hasMfa = user_has_mfa($user['id']);
?>
<div class="grid">
<div class="card"><h2>Profile</h2>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="profile">
<div class="stack"><?= field('Name', '<input name="name" required value="' . e($user['name']) . '">') ?>
<?= field('Email', '<input value="' . e($user['email']) . '" disabled>') ?>
<?= field('Phone', '<input name="phone" value="' . e($user['phone']) . '">') ?>
<?php $ch = json_decode((string) ($user['notification_channels'] ?? ''), true) ?: []; ?>
<?php if (has_provider('email') || has_provider('sms')): ?>
<span class="muted">Send me my notifications also</span>
<?php if (has_provider('email')): ?><label class="row"><input type="checkbox" style="width:auto" name="notify_email" value="1"<?= !empty($ch['email']) ? ' checked' : '' ?>> by email</label><?php endif ?>
<?php if (has_provider('sms')): ?><label class="row"><input type="checkbox" style="width:auto" name="notify_sms" value="1"<?= !empty($ch['sms']) ? ' checked' : '' ?>> by SMS to my phone</label><?php endif ?>
<?php endif ?></div>
<div class="actions"><button class="primary">Save</button></div></form></div>

<div class="card"><h2>Password</h2>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="password">
<div class="stack"><?= field('Current password', '<input type="password" name="current" required autocomplete="current-password">') ?>
<?= field('New password', '<input type="password" name="new" required minlength="10" autocomplete="new-password">', 'At least 10 characters, with letters and numbers.') ?>
<?= field('New password again', '<input type="password" name="confirm" required autocomplete="new-password">') ?></div>
<div class="actions"><button class="primary">Change password</button></div></form></div>

<div class="card"><h2>Two-step sign-in</h2>
<?php if ($hasMfa): ?>
  <p><?= badge('active') ?> On since <?= e(fdate($user['mfa_enabled_at'])) ?>.</p>
  <?php if (!mfa_required($user)): ?><?= post_button('Turn off', ['action' => 'mfa_off'], 'danger', 'Turn off two-step sign-in?') ?><?php else: ?><p class="muted">Your role needs it, so it stays on.</p><?php endif ?>
<?php else: ?>
  <p class="muted">Off. Turn it on to protect your account with a code from your phone.</p>
  <a class="btn primary" href="<?= e(url('mfa-setup.php')) ?>">Turn on</a>
<?php endif ?>
</div>
</div>
<?php page_end();
