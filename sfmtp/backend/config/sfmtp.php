<?php

return [

    /*
    | Base URL of the Next.js web app (used in password-reset and invite links).
    */
    'web_url' => env('SFMTP_WEB_URL', 'http://localhost:3000'),

    /*
    | Proxies (web BFF, load balancer) whose X-Forwarded-* headers are trusted:
    | comma-separated IPs/CIDRs, or "*". Empty = trust none.
    */
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    /*
    | Modules loaded by App\Support\Modules\ModuleServiceProvider, in dependency
    | order (see docs/09-module-dependency-map.md).
    */
    'modules' => [
        'Identity',
        'Tenancy',
        'Access',
        'Audit',
        'Billing',
        'Platform',
        'Catalog',
        'FarmStructure',
        'Media',
        'Workforce',
        'Crops',
        'Livestock',
        'Sync',
        'Support',
        'Traceability',
        'Reporting',
    ],

    'jwt' => [
        'secret' => env('JWT_SECRET'),
        'issuer' => env('JWT_ISSUER', 'sfmtp'),
        'access_ttl' => (int) env('JWT_ACCESS_TTL', 900),          // 15 minutes
        'mfa_ttl' => (int) env('JWT_MFA_TTL', 300),                // pending-MFA token
        'leeway' => 5,
    ],

    'refresh' => [
        'ttl_web' => (int) env('REFRESH_TTL_WEB', 60 * 60 * 24 * 7),      // 7 days, sliding
        'ttl_mobile' => (int) env('REFRESH_TTL_MOBILE', 60 * 60 * 24 * 30), // 30 days, sliding
        // A just-rotated token presented again within this window is refused
        // without revoking the session (parallel refreshes are not theft).
        'reuse_grace_seconds' => 30,
    ],

    'invitations' => [
        'ttl_days' => (int) env('INVITATION_TTL_DAYS', 7),
        'max_resends' => 5,
    ],

    'security' => [
        'max_failed_logins' => 5,
        'lockout_seconds' => 900,
        'recovery_codes' => 10,
        // Farm role keys whose holders must enable MFA (platform admins always must).
        'mfa_required_farm_roles' => ['owner', 'accountant'],
    ],

    'idempotency' => [
        'ttl' => 60 * 60 * 48,
    ],

    'dashboards' => [
        'cache_ttl' => (int) env('DASHBOARD_CACHE_TTL', 60),
    ],

    /*
    | Photos and documents (docs/08 §3). Files are stored under their SHA-256,
    | so a retried upload is deduplicated. Use an S3-compatible disk in
    | production (MinIO in the Docker stack).
    */
    'media' => [
        'disk' => env('SFMTP_MEDIA_DISK', 'local'),
        'max_kb' => (int) env('SFMTP_MEDIA_MAX_KB', 10240),
        'mimes' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
    ],

    /*
    | Offline sync (docs/08). Push batches are capped; pull hides changes
    | younger than the lag so a slow transaction cannot be skipped by a cursor.
    */
    'sync' => [
        'max_mutations' => 200,
        'pull_limit' => 500,
        'pull_lag_seconds' => 2,
        'task_window_days' => 14,
        'max_clock_skew_seconds' => 300,
    ],
];
