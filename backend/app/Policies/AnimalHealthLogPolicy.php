<?php

namespace App\Policies;

use App\Models\Animal;
use App\Models\AnimalHealthLog;
use App\Models\User;

class AnimalHealthLogPolicy
{
    public function viewAny(User $user, Animal $animal): bool
    {
        return $user->canViewFarm($animal->farm);
    }

    public function view(User $user, AnimalHealthLog $log): bool
    {
        return $user->canViewFarm($log->animal->farm);
    }

    /**
     * Vaccination, feeding, weight, treatment — any farm member does
     * this day to day, not just the livestock manager.
     */
    public function create(User $user, Animal $animal): bool
    {
        return $user->canViewFarm($animal->farm);
    }

    public function update(User $user, AnimalHealthLog $log): bool
    {
        return $user->canManageLivestock($log->animal->farm) || $log->recorded_by === $user->id;
    }

    public function delete(User $user, AnimalHealthLog $log): bool
    {
        return $user->canManageLivestock($log->animal->farm);
    }
}
