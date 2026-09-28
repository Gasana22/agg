<?php
/*
 * Sign-in, lockout and two-step sign-in (TOTP).
 *
 * Session keys: uid (signed-in user), mfa_ok (second step done or not
 * needed), farm_id (current farm, see farm.php).
 */

const MAX_FAILED_LOGINS = 5;
const LOCKOUT_SECONDS = 900;

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $uid = $_SESSION['uid'] ?? null;
        $user = $uid ? row("SELECT * FROM users WHERE id = ? AND status = 'active'", [$uid]) : null;
        if ($uid && !$user) {
            session_destroy();
        }
    }
    return $user;
}

function is_platform_admin(?array $user = null): bool
{
    $user ??= current_user();
    return $user !== null && $user['user_type'] === 'platform_admin';
}

/** Pages behind sign-in call this first. */
function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }
    if (empty($_SESSION['mfa_ok'])) {
        redirect(user_has_mfa($user['id']) ? 'mfa.php' : 'mfa-setup.php');
    }
    verify_csrf();
    return $user;
}

/**
 * Check email and password. Returns the user, or an error message.
 * Five wrong passwords lock the account for 15 minutes.
 */
function attempt_login(string $email, string $password): array|string
{
    $email = mb_strtolower(trim($email));
    $user = row('SELECT * FROM users WHERE email = ?', [$email]);
    $generic = 'Wrong email or password.';

    if (!$user) {
        password_verify($password, '$2y$12$abcdefghijklmnopqrstuuJ2gsXQ2A0GJmjm3oRzvDSxY6mPHyPa'); // same time either way
        return $generic;
    }
    if ($user['status'] !== 'active') {
        return 'This account is disabled.';
    }
    if ($user['locked_until'] && strtotime($user['locked_until'] . ' UTC') > time()) {
        return 'Too many wrong passwords. Try again in 15 minutes.';
    }
    if (!password_verify($password, $user['password'])) {
        $failures = (int) $user['failed_logins'] + 1;
        $locked = $failures >= MAX_FAILED_LOGINS ? gmdate('Y-m-d H:i:s', time() + LOCKOUT_SECONDS) : null;
        q('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?', [$failures, $locked, $user['id']]);
        audit('auth.login_failed', null, ['type' => 'user', 'id' => $user['id']], null, ['reason' => 'password'], $user['id']);
        return $generic;
    }
    if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    q('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = ? WHERE id = ?', [now_utc(), $user['id']]);
    return $user;
}

function start_session_for(array $user): void
{
    session_regenerate_id(true);
    $_SESSION = ['uid' => $user['id']];
    $needs = user_has_mfa($user['id']) || mfa_required($user);
    $_SESSION['mfa_ok'] = !$needs;
    audit('auth.login', null, ['type' => 'user', 'id' => $user['id']], null, null, $user['id']);
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Owners, accountants, platform staff, and members of farms that ask for it. */
function mfa_required(array $user): bool
{
    if (!config('require_mfa', true)) {
        return false;
    }
    if ($user['user_type'] === 'platform_admin') {
        return true;
    }
    $memberships = rows("SELECT fu.id, fu.farm_id, fu.is_owner FROM farm_users fu JOIN farms f ON f.id = fu.farm_id
        WHERE fu.user_id = ? AND fu.status = 'active' AND f.status IN ('pending','active')", [$user['id']]);
    foreach ($memberships as $m) {
        if ($m['is_owner']) {
            return true;
        }
        $keys = array_column(rows('SELECT r.`key` FROM farm_user_roles fur JOIN farm_roles r ON r.id = fur.farm_role_id WHERE fur.farm_user_id = ?', [$m['id']]), 'key');
        if (array_intersect($keys, ['owner', 'accountant'])) {
            return true;
        }
        $settings = json_decode((string) val('SELECT settings FROM farm_settings WHERE farm_id = ?', [$m['farm_id']]), true) ?: [];
        if (!empty($settings['require_mfa_for_all'])) {
            return true;
        }
    }
    return false;
}

function user_has_mfa(string $userId): bool
{
    return (bool) val("SELECT 1 FROM user_mfa_factors WHERE user_id = ? AND type = 'totp' AND confirmed_at IS NOT NULL", [$userId]);
}

/* ---------- TOTP (RFC 6238, 30-second steps, 6 digits) ---------- */

function base32_encode(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bytes) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function base32_decode(string $s): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper(rtrim($s, '='))) as $c) {
        $i = strpos($alphabet, $c);
        if ($i === false) {
            continue;
        }
        $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

function totp_code(string $secret, int $step): string
{
    $key = base32_decode($secret);
    $h = hash_hmac('sha1', pack('J', $step), $key, true);
    $o = ord($h[19]) & 0x0f;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
}

/** The matching time step (±1 for clock drift), or null. */
function totp_match(string $secret, string $code, ?int $lastUsed = null): ?int
{
    $code = preg_replace('/\D/', '', $code);
    $now = intdiv(time(), 30);
    foreach ([$now, $now - 1, $now + 1] as $step) {
        if (($lastUsed === null || $step > $lastUsed) && hash_equals(totp_code($secret, $step), $code)) {
            return $step;
        }
    }
    return null;
}

/* Secrets are stored encrypted with the configured secret key. */
function seal(string $plain): string
{
    $key = hash('sha256', (string) config('secret_key'), true);
    $iv = random_bytes(12);
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

function unseal(string $sealed): ?string
{
    if (!str_starts_with($sealed, 'v1:')) {
        return null;
    }
    $raw = base64_decode(substr($sealed, 3), true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $key = hash('sha256', (string) config('secret_key'), true);
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? null : $plain;
}

/** Verify a code (or a recovery code) for the signed-in user. */
function verify_second_step(string $userId, string $code): bool
{
    $factor = row("SELECT * FROM user_mfa_factors WHERE user_id = ? AND type = 'totp' AND confirmed_at IS NOT NULL", [$userId]);
    if (!$factor) {
        return false;
    }
    $secret = unseal($factor['secret']);
    if ($secret !== null) {
        $step = totp_match($secret, $code, $factor['last_used_timestep'] !== null ? (int) $factor['last_used_timestep'] : null);
        if ($step !== null) {
            q('UPDATE user_mfa_factors SET last_used_timestep = ?, updated_at = ? WHERE id = ?', [$step, now_utc(), $factor['id']]);
            return true;
        }
    }
    // A recovery code works once.
    $hash = hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code)));
    $rc = row('SELECT id FROM mfa_recovery_codes WHERE user_id = ? AND code_hash = ? AND used_at IS NULL', [$userId, $hash]);
    if ($rc) {
        q('UPDATE mfa_recovery_codes SET used_at = ? WHERE id = ?', [now_utc(), $rc['id']]);
        audit('auth.recovery_code_used', null, ['type' => 'user', 'id' => $userId], null, null, $userId);
        return true;
    }
    return false;
}

/** Ten one-time recovery codes; only their hashes are stored. */
function new_recovery_codes(string $userId): array
{
    q('DELETE FROM mfa_recovery_codes WHERE user_id = ?', [$userId]);
    $codes = [];
    for ($i = 0; $i < 10; $i++) {
        $c = strtoupper(bin2hex(random_bytes(5)));
        $codes[] = substr($c, 0, 5) . '-' . substr($c, 5);
        insert('mfa_recovery_codes', ['id' => uuid(), 'user_id' => $userId, 'code_hash' => hash('sha256', $c), 'created_at' => now_utc()]);
    }
    return $codes;
}
