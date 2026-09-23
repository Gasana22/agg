<?php

namespace App\Modules\Platform\Application;

/**
 * Platform roles and their capabilities (docs/04-roles-and-permissions.md §5).
 * These roles are fixed; capabilities are checked with `platform.can:<cap>`.
 * No capability reaches farm operational data.
 */
final class PlatformRoles
{
    public const CAPABILITIES = [
        'dashboard.view' => 'Open the platform dashboard',
        'farms.view' => 'View farm metadata (never operational records)',
        'farms.approve' => 'Approve new farms, and suspend or unsuspend for any reason',
        'farms.suspend' => 'Suspend or unsuspend farms for non-payment',
        'farms.reset_owner_password' => 'Send a password-reset link to a farm owner',
        'users.view' => 'View user accounts',
        'users.manage' => 'Enable or disable accounts and manage platform staff',
        'plans.manage' => 'Create and edit subscription plans',
        'subscriptions.view' => 'View subscriptions and payments',
        'subscriptions.manage' => 'Record payments, change plans, cancel subscriptions',
        'catalog.manage' => 'Manage global catalogues',
        'integrations.manage' => 'Configure integration providers',
        'settings.manage' => 'Change platform settings',
        'system.view' => 'View health, logs, failed jobs and backups',
        'support.view' => 'View support tickets',
        'support.manage' => 'Reply to, assign and resolve support tickets',
        'support.access' => 'Use read-only farm access granted by an owner',
    ];

    /** @return array<string, array<int,string>> */
    public static function roles(): array
    {
        $all = array_keys(self::CAPABILITIES);

        return [
            'super_admin' => array_values(array_diff($all, ['support.access'])),
            'support' => [
                'dashboard.view', 'farms.view', 'farms.reset_owner_password', 'users.view', 'subscriptions.view',
                'system.view', 'support.view', 'support.manage', 'support.access',
            ],
            'billing' => [
                'dashboard.view', 'farms.view', 'farms.suspend', 'plans.manage',
                'subscriptions.view', 'subscriptions.manage', 'support.view',
            ],
        ];
    }
}
