<?php
/*
 * SFMTP configuration. Copy this file to config.php and fill it in.
 * config.php is never committed: it holds your database password and secret key.
 */
return [
    'app_name' => 'SFMTP',
    // Full address of the site, without a trailing slash (used in QR links).
    'app_url' => 'http://localhost/sfmtp',

    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'sfmtp',
        'user' => 'root',
        'password' => '',
    ],

    // 32+ random characters. Encrypts MFA secrets. Generate one with:
    //   php -r "echo bin2hex(random_bytes(32));"
    'secret_key' => 'change-me-to-a-long-random-string',

    // Show PHP errors on screen (development only).
    'debug' => false,

    // Owners, accountants and platform staff must use two-step sign-in (an
    // authenticator app). Turn off only for a local demo.
    'require_mfa' => true,
];
