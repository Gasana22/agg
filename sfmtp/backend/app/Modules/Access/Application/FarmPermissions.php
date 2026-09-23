<?php

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\Enums\PermissionScope;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Effective permissions of a farm member: the union of all their roles,
 * with the widest scope per permission (docs/04 §1).
 */
class FarmPermissions
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return array<string,PermissionScope> for the current request's member */
    public function current(): array
    {
        $membership = $this->context->membership();

        return $membership === null ? [] : $this->context->remember('permissions', fn () => $this->for($membership));
    }

    public function allows(string $permission): bool
    {
        return isset($this->current()[$permission]);
    }

    public function scope(string $permission): ?PermissionScope
    {
        return $this->current()[$permission] ?? null;
    }

    /** @return array<string,PermissionScope> */
    public function for(FarmUser $membership): array
    {
        if ($membership->is_owner) {
            // The owner always holds every permission (except the worker marker).
            return array_fill_keys(
                array_values(array_diff(PermissionRegistry::keys(), ['worker.self'])),
                PermissionScope::All,
            );
        }

        $rows = DB::table('farm_user_roles as fur')
            ->join('farm_role_permissions as frp', function ($join) {
                $join->on('frp.farm_role_id', '=', 'fur.farm_role_id')->on('frp.farm_id', '=', 'fur.farm_id');
            })
            ->join('permissions as p', 'p.id', '=', 'frp.permission_id')
            ->where('fur.farm_user_id', $membership->id)
            ->where('fur.farm_id', $membership->farm_id)
            ->get(['p.key', 'frp.scope']);

        $grants = [];
        foreach ($rows as $row) {
            $scope = PermissionScope::from($row->scope);
            $grants[$row->key] = isset($grants[$row->key]) ? $grants[$row->key]->widest($scope) : $scope;
        }

        return $grants;
    }
}
