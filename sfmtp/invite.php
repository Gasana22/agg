<?php
/* Accept an invitation to a farm's supplier or customer portal (the link the farm sent). */
require __DIR__ . '/inc/bootstrap.php';

$token = (string) input('token', 100);
$inv = preg_match('/^[0-9a-f]{48}$/', $token) ? row('SELECT * FROM portal_invitations WHERE token_hash = ?', [hash('sha256', $token)]) : null;
$farm = $inv ? row("SELECT * FROM farms WHERE id = ? AND status IN ('pending','active')", [$inv['farm_id']]) : null;
if (!$inv || !$farm) {
    http_response_code(404);
    page_start('Invitation', true);
    echo '<div class="card"><h2>This link does not work</h2><p>Check that you copied all of it, or ask the farm for a new invitation.</p></div>';
    page_end();
    exit;
}
$record = row('SELECT * FROM `' . PORTAL_KINDS[$inv['kind']] . '` WHERE id = ?', [$inv['record_id']]);
$status = invitation_status($inv);
$user = current_user() && !empty($_SESSION['mfa_ok']) ? current_user() : null;
$account = row('SELECT id, user_type FROM users WHERE email = ?', [$inv['email']]);
$self = 'invite.php?token=' . $token;

if (is_post()) {
    verify_csrf();
    try {
        if ($user) {
            $farmName = portal_accept($inv, $user);
        } else {
            $account && fail('An account with this email already exists. Sign in to accept the invitation.');
            $name = input('name', 255) ?? fail('Your name is required.');
            $pass = (string) ($_POST['password'] ?? '');
            if (strlen($pass) < 10 || !preg_match('/[A-Za-z]/', $pass) || !preg_match('/\d/', $pass)) {
                fail('The password needs at least 10 characters, with letters and numbers.');
            }
            $pass === (string) ($_POST['confirm'] ?? '') || fail('The two passwords are different.');
            $uid = uuid();
            $farmName = tx(function () use ($uid, $inv, $name, $pass) {
                // The emailed link proves the address.
                insert('users', ['id' => $uid, 'user_type' => 'party', 'name' => $name, 'email' => $inv['email'], 'phone' => input('phone', 32),
                    'password' => password_hash($pass, PASSWORD_DEFAULT), 'status' => 'active', 'email_verified_at' => now_utc(), 'failed_logins' => 0,
                    'created_at' => now_utc(), 'updated_at' => now_utc()]);
                return portal_accept($inv, row('SELECT * FROM users WHERE id = ?', [$uid]));
            });
            start_session_for(row('SELECT * FROM users WHERE id = ?', [$uid]));
        }
        flash('success', "Welcome: you are now connected to $farmName.");
        redirect('portal.php');
    } catch (Invalid $e) {
        flash('error', $e->getMessage());
        $_SESSION['old'] = ['name' => $_POST['name'] ?? '', 'phone' => $_POST['phone'] ?? ''];
        redirect($self);
    }
}

page_start('Invitation', true);
$what = $inv['kind'] === 'supplier'
    ? 'see the purchase orders the farm sends you, tell them what you dispatch, and send your invoices'
    : 'order the farm\'s products and follow your orders, invoices and deliveries';
echo '<div class="card"><h2>' . e($farm['name']) . ' invites you</h2><p>As <b>' . e($record['name'] ?? '') . '</b> you can ' . e($what) . '.</p>'
    . ($inv['message'] ? '<p>“' . e($inv['message']) . '”</p>' : '') . '<p class="muted">Sent to ' . e($inv['email']) . '</p>';
if ($status !== 'pending') {
    echo '<div class="flash error">' . e(match ($status) { 'accepted' => 'This invitation has already been used. Sign in to open your portal.', 'revoked' => 'This invitation was withdrawn.', default => 'This invitation has expired. Ask the farm for a new one.' }) . '</div>'
        . '<p><a class="btn" href="' . e(url('login.php')) . '">Sign in</a></p>';
} elseif ($user) {
    if (mb_strtolower($user['email']) !== $inv['email']) {
        echo '<p>You are signed in as ' . e($user['email']) . '. Sign out, then open this link again and sign in as ' . e($inv['email']) . '.</p>';
    } else {
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="token" value="' . e($token) . '"><button class="primary">Accept</button></form>';
    }
} elseif ($account) {
    $_SESSION['after_login'] = $self;
    echo '<p>You already have an account. Sign in, and you will come back here to accept.</p><p><a class="btn primary" href="' . e(url('login.php')) . '">Sign in</a></p>';
} else {
    echo '<h3>Create your account</h3><form method="post">' . csrf_field() . '<input type="hidden" name="token" value="' . e($token) . '"><div class="stack">'
        . field('Your name', '<input name="name" required maxlength="255" value="' . old('name') . '">')
        . field('Phone', '<input name="phone" maxlength="32" value="' . old('phone') . '">')
        . field('Password', '<input type="password" name="password" required autocomplete="new-password">', 'At least 10 characters, with letters and numbers.')
        . field('Password again', '<input type="password" name="confirm" required autocomplete="new-password">')
        . '</div><div class="actions"><button class="primary">Create account and accept</button></div></form>';
}
echo '</div>';
page_end();
