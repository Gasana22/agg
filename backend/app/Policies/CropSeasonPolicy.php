<?php

namespace App\Policies;

use App\Models\CropSeason;
use App\Models\Farm;
use App\Models\User;

class CropSeasonPolicy
{
    public function viewAny(User $user, Farm $farm): bool
    {
        return $user->canViewFarm($farm);
    }

    public function view(User $user, CropSeason $cropSeason): bool
    {
        return $user->canViewFarm($cropSeason->farm);
    }

    /**
     * Planning a season (budget, expected yield) is a management decision.
     */
    public function create(User $user, Farm $farm): bool
    {
        return $user->canManageCrops($farm);
    }

    public function update(User $user, CropSeason $cropSeason): bool
    {
        return $user->canManageCrops($cropSeason->farm);
    }

    public function delete(User $user, CropSeason $cropSeason): bool
    {
        return $user->canManageCrops($cropSeason->farm);
    }
}
