<?php
/* Small shared helpers: escaping, redirects, flash messages, CSRF, input, formatting. */

/** Escape for HTML. Use for every value printed into a page. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path, array $query = []): string
{
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    return $base . '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
}

function redirect(string $path, array $query = []): never
{
    header('Location: ' . url($path, $query));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = [$type, $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Every POST must carry the session's CSRF token. */
function verify_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $sent = (string) ($_POST['_csrf'] ?? '');
        if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
            http_response_code(419);
            exit('This form expired. Go back, reload the page and try again.');
        }
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Trimmed string input, or null when empty. */
function input(string $key, ?int $max = 500): ?string
{
    $v = $_POST[$key] ?? $_GET[$key] ?? null;
    if ($v === null || is_array($v)) {
        return null;
    }
    $v = trim((string) $v);
    if ($v === '') {
        return null;
    }
    return $max !== null ? mb_substr($v, 0, $max) : $v;
}

function input_num(string $key): ?float
{
    $v = input($key, 40);
    if ($v === null) {
        return null;
    }
    $v = str_replace([',', ' '], '', $v);
    return is_numeric($v) ? (float) $v : null;
}

function input_date(string $key): ?string
{
    $v = input($key, 10);
    if ($v === null) {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v ? $v : null;
}

/** An id that must be a UUID. */
function input_id(string $key): ?string
{
    $v = input($key, 36);
    return $v !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $v) ? $v : null;
}

/** One of the allowed values, or null. */
function input_in(string $key, array $allowed): ?string
{
    $v = input($key, 60);
    return in_array($v, $allowed, true) ? $v : null;
}

/** Collects form errors; stop() sends them back to the form. */
final class Invalid extends RuntimeException
{
}

function fail(string $message): never
{
    throw new Invalid($message);
}

/** Run a form handler; errors become a flash message and the user returns to $back. */
function handle(callable $fn, string $back, array $backQuery = []): never
{
    try {
        $fn();
    } catch (Invalid $e) {
        flash('error', $e->getMessage());
        $_SESSION['old'] = $_POST;
        redirect($back, $backQuery);
    }
    redirect($back, $backQuery);
}

/** Previous input after a failed form. */
function old(string $key, mixed $default = ''): string
{
    return e($_SESSION['old_view'][$key] ?? $default);
}

function money(mixed $amount, ?string $currency = null): string
{
    if ($amount === null || $amount === '') {
        return '—';
    }
    $cur = $currency ?? (current_farm()['currency'] ?? 'UGX');
    $decimals = in_array($cur, ['UGX', 'RWF', 'TZS', 'KES'], true) ? 0 : 2;
    return $cur . ' ' . number_format((float) $amount, $decimals);
}

function qty(mixed $n, ?string $unit = null): string
{
    if ($n === null || $n === '') {
        return '—';
    }
    $s = rtrim(rtrim(number_format((float) $n, 3, '.', ','), '0'), '.');
    return $unit ? "$s $unit" : $s;
}

/** Date or datetime in the farm's time zone. */
function fdate(?string $value, bool $time = false): string
{
    if (!$value) {
        return '—';
    }
    if (!$time && strlen($value) === 10) {
        return date('j M Y', strtotime($value . ' 00:00:00 UTC'));
    }
    $tz = new DateTimeZone(current_farm()['timezone'] ?? 'Africa/Kampala');
    $d = new DateTime($value, new DateTimeZone('UTC'));
    $d->setTimezone($tz);
    return $d->format($time ? 'j M Y H:i' : 'j M Y');
}

/** Today's date in the farm's time zone. */
function farm_today(): string
{
    $tz = new DateTimeZone(current_farm()['timezone'] ?? 'Africa/Kampala');
    return (new DateTime('now', $tz))->format('Y-m-d');
}

function label(?string $value): string
{
    return $value === null ? '—' : ucfirst(str_replace('_', ' ', $value));
}

function badge(?string $status): string
{
    $tone = match ($status) {
        'active', 'verified', 'approved', 'paid', 'delivered', 'resolved', 'issued', 'open' => 'ok',
        'submitted', 'requested', 'pending', 'in_progress', 'assigned', 'monitoring', 'dispatched', 'draft', 'trialing' => 'warn',
        'rejected', 'void', 'failed', 'suspended', 'recalled', 'revoked', 'dead', 'cancelled' => 'bad',
        default => 'neutral',
    };
    return '<span class="badge ' . $tone . '">' . e(label($status)) . '</span>';
}

/** <option> list from rows. */
function options(array $rows, string $valueKey, string|callable $labelKey, ?string $selected = null, bool $blank = true): string
{
    $html = $blank ? '<option value="">—</option>' : '';
    foreach ($rows as $r) {
        $v = $r[$valueKey];
        $l = is_callable($labelKey) ? $labelKey($r) : $r[$labelKey];
        $html .= '<option value="' . e($v) . '"' . ($selected !== null && (string) $selected === (string) $v ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    return $html;
}

function enum_options(array $values, ?string $selected = null, bool $blank = false): string
{
    $html = $blank ? '<option value="">—</option>' : '';
    foreach ($values as $v) {
        $html .= '<option value="' . e($v) . '"' . ($selected === $v ? ' selected' : '') . '>' . e(label($v)) . '</option>';
    }
    return $html;
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}
