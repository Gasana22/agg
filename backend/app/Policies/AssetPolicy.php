<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\Farm;
use App\Models\User;

class AssetPolicy
{
    public function viewAny(User $user, Farm $farm): bool
    {
        return $user->canViewFarm($farm);
    }

    public function view(User $user, Asset $asset): bool
    {
        return $user->canViewFarm($asset->farm);
    }

    public function create(User $user, Farm $farm): bool
    {
        return $user->canManageAssets($farm);
    }

    public function update(User $user, Asset $asset): bool
    {
        return $user->canManageAssets($asset->farm);
    }

    public function delete(User $user, Asset $asset): bool
    {
        return $user->canManageAssets($asset->farm);
    }
}
