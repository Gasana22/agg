<?php

namespace App\Policies;

use App\Models\Farm;
use App\Models\PoultryFlock;
use App\Models\User;

class PoultryFlockPolicy
{
    public function viewAny(User $user, Farm $farm): bool
    {
        return $user->canViewFarm($farm);
    }

    public function view(User $user, PoultryFlock $flock): bool
    {
        return $user->canViewFarm($flock->farm);
    }

    public function create(User $user, Farm $farm): bool
    {
        return $user->canManageLivestock($farm);
    }

    public function update(User $user, PoultryFlock $flock): bool
    {
        return $user->canManageLivestock($flock->farm);
    }

    public function delete(User $user, PoultryFlock $flock): bool
    {
        return $user->canManageLivestock($flock->farm);
    }
}
