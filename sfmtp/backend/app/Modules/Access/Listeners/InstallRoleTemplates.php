<?php

namespace App\Modules\Access\Listeners;

use App\Modules\Access\Application\PermissionCatalog;
use App\Modules\Access\Application\RoleTemplates;
use App\Modules\Access\Domain\Models\FarmRole;
use App\Modules\Tenancy\Domain\Events\FarmCreated;
use Illuminate\Support\Facades\DB;

/**
 * Copies the role templates into a new farm and gives the creator the owner
 * role. Runs synchronously inside the farm-creation transaction.
 */
class InstallRoleTemplates
{
    public function __construct(private readonly PermissionCatalog $catalog) {}

    public function handle(FarmCreated $event): void
    {
        $this->catalog->ensureSynced();
        $permissionIds = $this->catalog->ids();
        $farmId = $event->farm->id;

        foreach (RoleTemplates::all() as $key => $template) {
            $role = FarmRole::create([
                'farm_id' => $farmId,
                'key' => $key,
                'name' => $template['name'],
                'description' => $template['description'],
                'is_system' => true,
                'is_locked' => $key === RoleTemplates::OWNER,
            ]);

            DB::table('farm_role_permissions')->insert(array_map(fn ($perm, $scope) => [
                'farm_id' => $farmId,
                'farm_role_id' => $role->id,
                'permission_id' => $permissionIds[$perm],
                'scope' => $scope,
            ], array_keys($template['grants']), $template['grants']));

            if ($key === RoleTemplates::OWNER) {
                DB::table('farm_user_roles')->insert([
                    'farm_id' => $farmId,
                    'farm_user_id' => $event->owner->id,
                    'farm_role_id' => $role->id,
                    'created_at' => now(),
                ]);
            }
        }
    }
}
