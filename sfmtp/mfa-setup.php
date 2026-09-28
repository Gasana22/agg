<?php
/* Turn on two-step sign-in: required for owners, accountants and platform staff. */
require __DIR__ . '/inc/bootstrap.php';

$user = current_user();
if (!$user) {
    redirect('login.php');
}
if (user_has_mfa($user['id']) && empty($_SESSION['mfa_codes'])) {
    redirect(empty($_SESSION['mfa_ok']) ? 'mfa.php' : 'profile.php');
}

// Show the recovery codes once, right after setup.
if (!empty($_SESSION['mfa_codes'])) {
    $codes = $_SESSION['mfa_codes'];
    unset($_SESSION['mfa_codes']);
    page_start('Recovery codes', true);
    echo '<div class="card"><h2>Save your recovery codes</h2><p>Each code signs you in once if you lose your phone. Keep them somewhere safe: they are shown only now.</p><pre class="code">'
        . e(implode("\n", $codes)) . '</pre><div class="actions"><button type="button" data-print>Print</button><a class="btn primary" href="' . e(url('index.php')) . '">I saved them, continue</a></div></div>';
    page_end();
    exit;
}

if (empty($_SESSION['mfa_secret'])) {
    $_SESSION['mfa_secret'] = base32_encode(random_bytes(20));
}
$secret = $_SESSION['mfa_secret'];

if (is_post()) {
    verify_csrf();
    if (totp_match($secret, (string) ($_POST['code'] ?? '')) === null) {
        flash('error', 'That code does not match. Check the time on your phone and try the newest code.');
        redirect('mfa-setup.php');
    }
    tx(function () use ($user, $secret) {
        q("DELETE FROM user_mfa_factors WHERE user_id = ? AND type = 'totp'", [$user['id']]);
        insert('user_mfa_factors', ['id' => uuid(), 'user_id' => $user['id'], 'type' => 'totp', 'secret' => seal($secret),
            'last_used_timestep' => intdiv(time(), 30), 'confirmed_at' => now_utc(), 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        q('UPDATE users SET mfa_enabled_at = ? WHERE id = ?', [now_utc(), $user['id']]);
        $_SESSION['mfa_codes'] = new_recovery_codes($user['id']);
        audit('auth.mfa_enabled', null, ['type' => 'user', 'id' => $user['id']], null, null, $user['id']);
    });
    unset($_SESSION['mfa_secret']);
    session_regenerate_id(true);
    $_SESSION['mfa_ok'] = true;
    redirect('mfa-setup.php');
}

$issuer = config('app_name', 'SFMTP');
$uri = 'otpauth://totp/' . rawurlencode($issuer . ':' . $user['email']) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
page_start('Set up two-step sign-in', true);
?>
<div class="card">
  <h2>Set up two-step sign-in</h2>
  <p>Your role needs a second step when you sign in. Install an authenticator app (Google Authenticator, Microsoft Authenticator or Authy), choose <b>Enter a setup key</b>, and type this key:</p>
  <p class="qr" style="font-size:1.2rem;word-break:break-all"><b><?= e(trim(chunk_split($secret, 4, ' '))) ?></b></p>
  <p class="muted">Account: <?= e($user['email']) ?> · Time based. On a phone you can also <a href="<?= e($uri) ?>">open it in the app</a>.</p>
  <form method="post">
    <?= csrf_field() ?>
    <?= field('Code from the app', '<input name="code" inputmode="numeric" autocomplete="one-time-code" required>') ?>
    <div class="actions"><button class="primary">Turn on</button></div>
  </form>
</div>
<form method="post" action="logout.php"><?= csrf_field() ?><button class="link">Sign out</button></form>
<?php page_end();
