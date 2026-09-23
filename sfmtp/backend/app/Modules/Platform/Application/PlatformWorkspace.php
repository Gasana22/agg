<?php

namespace App\Modules\Platform\Application;

use App\Modules\Access\Contracts\WorkspaceContributor;
use App\Modules\Identity\Domain\Models\User;

/** Puts the admin workspace first for platform staff, with their capabilities. */
class PlatformWorkspace implements WorkspaceContributor
{
    public function __construct(private readonly PlatformPermissions $permissions) {}

    public function contribute(User $user, array $workspaces): array
    {
        if (! $user->isPlatformAdmin()) {
            return $workspaces;
        }

        $capabilities = $this->permissions->capabilitiesOf($user);
        array_unshift($workspaces, [
            'type' => 'platform',
            'id' => 'platform',
            'name' => 'SFMTP Administration',
            'roles' => array_map(fn ($r) => ['key' => $r, 'name' => ucwords(str_replace('_', ' ', $r))], $this->permissions->rolesOf($user)),
            'permissions' => array_fill_keys($capabilities, 'all'),
            'dashboards' => in_array('dashboard.view', $capabilities, true) ? ['admin'] : [],
        ]);

        return $workspaces;
    }
}
