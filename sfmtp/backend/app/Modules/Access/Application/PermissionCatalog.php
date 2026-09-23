<?php

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\Models\Permission;

/**
 * Keeps the `permissions` table in step with PermissionRegistry.
 */
class PermissionCatalog
{
    public function sync(): void
    {
        foreach (PermissionRegistry::all() as $key => $def) {
            Permission::updateOrCreate(['key' => $key], [
                'module' => $def['module'],
                'description' => $def['description'],
                'scopes' => $def['scopes'],
                'owner_only' => $def['owner_only'],
                'money' => $def['money'],
            ]);
        }
    }

    public function ensureSynced(): void
    {
        if (Permission::count() !== count(PermissionRegistry::all())) {
            $this->sync();
        }
    }

    /** @return array<string,string> key => permission id */
    public function ids(): array
    {
        return Permission::pluck('id', 'key')->all();
    }
}
