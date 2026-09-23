<?php

namespace App\Modules\Support\Application;

use App\Modules\Access\Application\Dashboards;
use App\Modules\Access\Application\FarmPermissions;
use App\Modules\Access\Contracts\WorkspaceContributor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Platform\Application\PlatformPermissions;
use App\Modules\Tenancy\Domain\Models\Farm;

/**
 * - Support staff: one read-only workspace per farm they hold a grant for.
 * - Owners: their farm workspaces show when support access is active.
 */
class SupportWorkspaces implements WorkspaceContributor
{
    public function __construct(
        private readonly SupportAccess $access,
        private readonly PlatformPermissions $platform,
    ) {}

    public function contribute(User $user, array $workspaces): array
    {
        foreach ($workspaces as &$workspace) {
            if (($workspace['type'] ?? null) === 'farm' && ($workspace['is_owner'] ?? false)) {
                $grant = $this->access->activeGrantFor($workspace['id']);
                $workspace['support_access'] = $grant ? ['grant_id' => $grant->id, 'expires_at' => $grant->expires_at->toIso8601ZuluString()] : null;
            }
        }
        unset($workspace);

        if ($this->platform->allows($user, 'support.access')) {
            $grants = $this->access->activeGrants()
                ->where(fn ($q) => $q->whereNull('grantee_user_id')->orWhere('grantee_user_id', $user->id))
                ->get()->unique('farm_id');
            $permissions = FarmPermissions::supportReadOnly();

            foreach ($grants as $grant) {
                $farm = Farm::find($grant->farm_id);
                if ($farm === null) {
                    continue;
                }
                $workspaces[] = [
                    'type' => 'support',
                    'id' => $farm->id,
                    'name' => $farm->name.' (support, read-only)',
                    'code' => $farm->code,
                    'status' => $farm->status->value,
                    'is_owner' => false,
                    'roles' => [],
                    'permissions' => array_map(fn ($s) => $s->value, $permissions),
                    'dashboards' => array_values(array_intersect(['owner'], Dashboards::available($permissions))),
                    'support_access' => ['grant_id' => $grant->id, 'expires_at' => $grant->expires_at->toIso8601ZuluString()],
                ];
            }
        }

        return $workspaces;
    }
}
