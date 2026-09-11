<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\AssetMaintenanceLog;
use App\Models\User;

class AssetMaintenanceLogPolicy
{
    public function viewAny(User $user, Asset $asset): bool
    {
        return $user->canViewFarm($asset->farm);
    }

    public function view(User $user, AssetMaintenanceLog $log): bool
    {
        return $user->canViewFarm($log->asset->farm);
    }

    /**
     * Any farm member who services or repairs equipment can log it — not
     * just the store_manager — matching the AnimalHealthLog precedent.
     */
    public function create(User $user, Asset $asset): bool
    {
        return $user->canViewFarm($asset->farm);
    }

    public function update(User $user, AssetMaintenanceLog $log): bool
    {
        return $user->canManageAssets($log->asset->farm) || $log->recorded_by === $user->id;
    }

    public function delete(User $user, AssetMaintenanceLog $log): bool
    {
        return $user->canManageAssets($log->asset->farm);
    }
}
