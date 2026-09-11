<?php

namespace App\Policies;

use App\Models\CropHarvest;
use App\Models\CropSeason;
use App\Models\User;

class CropHarvestPolicy
{
    public function viewAny(User $user, CropSeason $cropSeason): bool
    {
        return $user->canViewFarm($cropSeason->farm);
    }

    public function view(User $user, CropHarvest $harvest): bool
    {
        return $user->canViewFarm($harvest->cropSeason->farm);
    }

    /**
     * Whoever is harvesting can log the quantity; corrections (quality
     * grade disputes, mistaken entries) are manager-only.
     */
    public function create(User $user, CropSeason $cropSeason): bool
    {
        return $user->canViewFarm($cropSeason->farm);
    }

    public function update(User $user, CropHarvest $harvest): bool
    {
        return $user->canManageCrops($harvest->cropSeason->farm);
    }

    public function delete(User $user, CropHarvest $harvest): bool
    {
        return $user->canManageCrops($harvest->cropSeason->farm);
    }
}
