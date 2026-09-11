<?php

namespace App\Policies;

use App\Models\Animal;
use App\Models\AnimalProductionRecord;
use App\Models\User;

class AnimalProductionRecordPolicy
{
    public function viewAny(User $user, Animal $animal): bool
    {
        return $user->canViewFarm($animal->farm);
    }

    public function view(User $user, AnimalProductionRecord $record): bool
    {
        return $user->canViewFarm($record->animal->farm);
    }

    /**
     * Whoever milks the cow or collects the eggs logs it.
     */
    public function create(User $user, Animal $animal): bool
    {
        return $user->canViewFarm($animal->farm);
    }

    public function update(User $user, AnimalProductionRecord $record): bool
    {
        return $user->canManageLivestock($record->animal->farm) || $record->recorded_by === $user->id;
    }

    public function delete(User $user, AnimalProductionRecord $record): bool
    {
        return $user->canManageLivestock($record->animal->farm);
    }
}
