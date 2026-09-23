<?php

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\Models\FarmRole;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    /**
     * Replace a member's roles (the owner's membership is never edited here).
     *
     * @param  iterable<FarmRole>  $roles
     */
    public function replaceMemberRoles(FarmUser $member, iterable $roles): void
    {
        $before = DB::table('farm_user_roles')->join('farm_roles', 'farm_roles.id', '=', 'farm_user_roles.farm_role_id')
            ->where('farm_user_roles.farm_user_id', $member->id)->orderBy('farm_roles.key')->pluck('farm_roles.key')->all();

        DB::table('farm_user_roles')->where('farm_user_id', $member->id)->where('farm_id', $member->farm_id)->delete();
        $after = [];
        foreach ($roles as $role) {
            if ($role->key === RoleTemplates::OWNER) {
                throw ApiException::unprocessable('guard_rail_owner_role', 'The owner role can only be held by the farm owner. Use ownership transfer instead.');
            }
            DB::table('farm_user_roles')->insert([
                'farm_id' => $member->farm_id,
                'farm_user_id' => $member->id,
                'farm_role_id' => $role->id,
                'created_at' => now(),
            ]);
            $after[] = $role->key;
        }
        sort($after);

        if ($before !== $after) {
            $this->audit->record('member.roles_changed', $member, ['roles' => $before], ['roles' => $after]);
        }
    }

    /**
     * A custom role, optionally starting from another role's grants.
     *
     * @param  array{name:string, description?:?string, copy_from?:?FarmRole, grants?:array<string,string>}  $attributes
     */
    public function createRole(array $attributes): FarmRole
    {
        $key = Str::slug($attributes['name'], '_');
        if ($key === '' || FarmRole::where('key', $key)->exists() || $key === RoleTemplates::OWNER) {
            throw ApiException::conflict('duplicate', 'A role with this name already exists in the farm.');
        }

        $grants = $attributes['grants'] ?? null;
        if ($grants === null && isset($attributes['copy_from'])) {
            // Owner-only permissions stay with the owner, even when copying the owner role.
            $grants = $attributes['copy_from']->permissions
                ->reject(fn ($p) => $p->owner_only)
                ->mapWithKeys(fn ($p) => [$p->key => $p->pivot->scope])->all();
        }
        $grants ??= [];

        return DB::transaction(function () use ($attributes, $key, $grants) {
            $role = FarmRole::create([
                'key' => $key,
                'name' => $attributes['name'],
                'description' => $attributes['description'] ?? null,
                'is_system' => false,
                'is_locked' => false,
            ]);
            $this->audit->record('role.created', $role, null, ['key' => $key, 'name' => $role->name]);

            return $grants === [] ? $role->load('permissions') : $this->syncPermissions($role, $grants);
        });
    }

    /** @param  array{name?:string, description?:?string}  $attributes */
    public function updateRole(FarmRole $role, array $attributes): FarmRole
    {
        if ($role->is_locked) {
            throw ApiException::forbidden('role_locked', 'This role cannot be edited.');
        }

        $before = $role->only(['name', 'description']);
        $role->fill(array_intersect_key($attributes, array_flip(['name', 'description'])));
        if ($role->isDirty()) {
            $role->save();
            $this->audit->record('role.updated', $role, $before, $role->only(['name', 'description']));
        }

        return $role->load('permissions');
    }

    /** Delete a custom role that nobody holds and no pending invitation offers. */
    public function deleteRole(FarmRole $role): void
    {
        if ($role->is_system || $role->is_locked) {
            throw ApiException::forbidden('system_role', 'Built-in roles cannot be deleted. Remove their permissions instead.');
        }

        $members = DB::table('farm_user_roles')->where('farm_role_id', $role->id)->count();
        $invitations = DB::table('farm_invitation_roles')
            ->join('farm_invitations', 'farm_invitations.id', '=', 'farm_invitation_roles.invitation_id')
            ->where('farm_invitation_roles.farm_role_id', $role->id)
            ->whereNull('farm_invitations.accepted_at')->whereNull('farm_invitations.revoked_at')
            ->where('farm_invitations.expires_at', '>', now())
            ->count();
        if ($members + $invitations > 0) {
            throw ApiException::conflict('role_in_use', "This role is held by {$members} member(s) and offered in {$invitations} pending invitation(s). Reassign them first.");
        }

        DB::transaction(function () use ($role) {
            // Spent invitations keep no link to a deleted role.
            DB::table('farm_invitation_roles')->where('farm_role_id', $role->id)->delete();
            $this->audit->record('role.deleted', $role, ['key' => $role->key, 'name' => $role->name, 'grants' => $this->grantsOf($role)], null);
            $role->delete();   // grants cascade
        });
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
