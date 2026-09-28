<?php

/*
 * Browsers never call the API directly: the web app goes through its
 * backend-for-frontend, and the phone app and devices are not browsers.
 * Cross-origin calls are therefore limited to the web app's own origin.
 */
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter([env('SFMTP_WEB_URL', 'http://localhost:3000')])),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'Idempotency-Key', 'If-Match', 'X-Request-Id'],
    'exposed_headers' => ['X-Request-Id', 'ETag'],
    'max_age' => 600,
    'supports_credentials' => false,
];
