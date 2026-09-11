<?php

namespace App\Policies;

use App\Models\Animal;
use App\Models\Farm;
use App\Models\User;

class AnimalPolicy
{
    public function viewAny(User $user, Farm $farm): bool
    {
        return $user->canViewFarm($farm);
    }

    public function view(User $user, Animal $animal): bool
    {
        return $user->canViewFarm($animal->farm);
    }

    public function create(User $user, Farm $farm): bool
    {
        return $user->canManageLivestock($farm);
    }

    public function update(User $user, Animal $animal): bool
    {
        return $user->canManageLivestock($animal->farm);
    }

    public function delete(User $user, Animal $animal): bool
    {
        return $user->canManageLivestock($animal->farm);
    }
}
