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
];
