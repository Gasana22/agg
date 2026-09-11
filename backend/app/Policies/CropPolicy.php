<?php

namespace App\Policies;

use App\Models\Crop;
use App\Models\Farm;
use App\Models\User;

class CropPolicy
{
    public function viewAny(User $user, Farm $farm): bool
    {
        return $user->canViewFarm($farm);
    }

    public function view(User $user, Crop $crop): bool
    {
        return $user->canViewFarm($crop->farm);
    }

    public function create(User $user, Farm $farm): bool
    {
        return $user->canManageCrops($farm);
    }

    public function update(User $user, Crop $crop): bool
    {
        return $user->canManageCrops($crop->farm);
    }

    public function delete(User $user, Crop $crop): bool
    {
        return $user->canManageCrops($crop->farm);
    }
}
