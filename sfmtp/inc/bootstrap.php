<?php
/*
 * Loaded first by every page: configuration, errors, session, database and
 * the shared helpers. Pages then call require_login() / require_farm().
 */
declare(strict_types=1);

define('ROOT', dirname(__DIR__));

$configFile = ROOT . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('SFMTP is not configured yet: copy config.sample.php to config.php and fill it in (see README.md).');
}
$GLOBALS['config'] = require $configFile;

function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['config'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

error_reporting(E_ALL);
ini_set('display_errors', config('debug') ? '1' : '0');
date_default_timezone_set('UTC');

// Unexpected errors: log the detail, show a plain message (the detail only in debug mode).
set_exception_handler(function (Throwable $e) {
    error_log('SFMTP: ' . $e);
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<!doctype html><meta charset="utf-8"><title>Error</title><div style="font-family:system-ui;max-width:640px;margin:3rem auto">'
        . '<h1>Something went wrong</h1><p>The error was logged. Go back and try again; if it keeps happening, tell the platform team.</p>'
        . (config('debug') ? '<pre style="white-space:pre-wrap">' . htmlspecialchars((string) $e) . '</pre>' : '') . '</div>';
});

// Security headers on every page.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'");

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('sfmtp');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

require __DIR__ . '/defaults.php';
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/farm.php';
require __DIR__ . '/audit.php';
require __DIR__ . '/trace.php';
require __DIR__ . '/ledger.php';
require __DIR__ . '/stock.php';
require __DIR__ . '/layout.php';
