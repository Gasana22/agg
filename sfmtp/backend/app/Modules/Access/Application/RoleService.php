<?php

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\Models\FarmRole;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Role editing with the guard-rails the owner cannot override (docs/04 §4).
 */
class RoleService
{
    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Replace a role's grants.
     *
     * @param  array<string,string>  $grants  permission key => scope
     */
    public function syncPermissions(FarmRole $role, array $grants): FarmRole
    {
        $this->assertGrantable($role, $grants);

        $before = $this->grantsOf($role);
        $ids = $this->catalog->ids();

        DB::transaction(function () use ($role, $grants, $ids) {
            DB::table('farm_role_permissions')->where('farm_role_id', $role->id)->delete();
            if ($grants) {
                DB::table('farm_role_permissions')->insert(array_map(fn ($key, $scope) => [
                    'farm_id' => $role->farm_id,
                    'farm_role_id' => $role->id,
                    'permission_id' => $ids[$key],
                    'scope' => $scope,
                ], array_keys($grants), $grants));
            }
        });

        $this->audit->record('role.permissions_changed', $role, $before, $grants);

        return $role->load('permissions');
    }

    public function assignRole(FarmUser $member, FarmRole $role): void
    {
        if ($role->key === RoleTemplates::OWNER && ! $member->is_owner) {
            throw ApiException::unprocessable('guard_rail_owner_role', 'The owner role can only be held by the farm owner. Use ownership transfer instead.');
        }

        DB::table('farm_user_roles')->insertOrIgnore([
            'farm_id' => $member->farm_id,
            'farm_user_id' => $member->id,
            'farm_role_id' => $role->id,
            'created_at' => now(),
        ]);

        $this->audit->record('member.role_assigned', $member, null, ['role' => $role->key]);
    }

    /** @param  array<string,string>  $grants */
    private function assertGrantable(FarmRole $role, array $grants): void
    {
        if ($role->is_locked) {
            throw ApiException::forbidden('role_locked', 'This role cannot be edited.');
        }

        $registry = PermissionRegistry::all();
        $errors = [];

        foreach ($grants as $key => $scope) {
            if (! isset($registry[$key])) {
                $errors["grants.{$key}"][] = 'Unknown permission.';

                continue;
            }
            if (! in_array($scope, $registry[$key]['scopes'], true)) {
                $errors["grants.{$key}"][] = 'Scope must be one of: '.implode(', ', $registry[$key]['scopes']).'.';
            }
            if ($registry[$key]['owner_only']) {
                $errors["grants.{$key}"][] = 'This permission can only be held by the farm owner.';
            }
        }

        if (isset($grants['worker.self'])) {
            foreach (array_keys($grants) as $key) {
                if (($registry[$key]['money'] ?? false) === true) {
                    $errors["grants.{$key}"][] = 'Field-worker roles can never see or change money.';
                }
            }
        }

        if ($errors) {
            throw ApiException::unprocessable('guard_rail_violation', 'Some permissions cannot be granted to this role.', $errors);
        }
    }

    /** @return array<string,string> */
    private function grantsOf(FarmRole $role): array
    {
        return $role->permissions()->get()->mapWithKeys(fn ($p) => [$p->key => $p->pivot->scope])->all();
    }
}
