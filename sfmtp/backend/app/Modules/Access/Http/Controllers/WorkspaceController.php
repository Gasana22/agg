<?php

namespace App\Modules\Access\Http\Controllers;

use App\Modules\Access\Application\Dashboards;
use App\Modules\Access\Application\FarmPermissions;
use App\Modules\Access\Contracts\WorkspaceContributor;
use App\Modules\Identity\Contracts\MfaRequirement;
use App\Modules\Tenancy\Domain\Enums\FarmStatus;
use App\Modules\Tenancy\Domain\Enums\MembershipStatus;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /me/workspaces — every workspace (platform admin, farms, portals) the
 * signed-in user can enter, with the permissions the web app uses to build
 * its navigation. The server still enforces every call.
 */
class WorkspaceController
{
    public function __construct(
        private readonly FarmPermissions $permissions,
        private readonly MfaRequirement $mfa,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $workspaces = [];

        $memberships = FarmUser::with('farm')
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active->value)
            ->get()
            ->filter(fn (FarmUser $m) => $m->farm && $m->farm->status !== FarmStatus::Closed)
            ->sortBy(fn (FarmUser $m) => $m->farm->name);

        $roles = DB::table('farm_user_roles')
            ->join('farm_roles', 'farm_roles.id', '=', 'farm_user_roles.farm_role_id')
            ->whereIn('farm_user_roles.farm_user_id', $memberships->pluck('id'))
            ->orderBy('farm_roles.name')
            ->get(['farm_user_roles.farm_user_id', 'farm_roles.key', 'farm_roles.name'])
            ->groupBy('farm_user_id');

        foreach ($memberships as $membership) {
            $grants = $this->permissions->for($membership);
            $workspaces[] = [
                'type' => 'farm',
                'id' => $membership->farm->id,
                'name' => $membership->farm->name,
                'code' => $membership->farm->code,
                'status' => $membership->farm->status->value,
                'is_owner' => $membership->is_owner,
                'roles' => ($roles[$membership->id] ?? collect())->map(fn ($r) => ['key' => $r->key, 'name' => $r->name])->values(),
                'permissions' => array_map(fn ($scope) => $scope->value, $grants),
                'dashboards' => Dashboards::available($grants),
            ];
        }

        foreach (app()->tagged('sfmtp.workspace-contributors') as $contributor) {
            /** @var WorkspaceContributor $contributor */
            $workspaces = $contributor->contribute($user, $workspaces);
        }

        return new JsonResponse([
            'data' => $workspaces,
            'meta' => [
                'default_workspace' => $workspaces[0]['id'] ?? null,
                'mfa_enabled' => $user->hasMfa(),
                'mfa_required' => $this->mfa->requiresMfa($user),
            ],
        ]);
    }
}
