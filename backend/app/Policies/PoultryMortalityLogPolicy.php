<?php

namespace App\Policies;

use App\Models\PoultryFlock;
use App\Models\PoultryMortalityLog;
use App\Models\User;

class PoultryMortalityLogPolicy
{
    public function viewAny(User $user, PoultryFlock $flock): bool
    {
        return $user->canViewFarm($flock->farm);
    }

    public function view(User $user, PoultryMortalityLog $log): bool
    {
        return $user->canViewFarm($log->flock->farm);
    }

    /**
     * Whoever finds a dead bird logs it — same day-to-day treatment as
     * AnimalHealthLogPolicy::create.
     */
    public function create(User $user, PoultryFlock $flock): bool
    {
        return $user->canViewFarm($flock->farm);
    }

    public function update(User $user, PoultryMortalityLog $log): bool
    {
        return $user->canManageLivestock($log->flock->farm) || $log->recorded_by === $user->id;
    }

    public function delete(User $user, PoultryMortalityLog $log): bool
    {
        return $user->canManageLivestock($log->flock->farm);
    }
}
