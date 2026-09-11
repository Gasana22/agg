<?php

namespace App\Policies;

use App\Models\BreedingRecord;
use App\Models\Farm;
use App\Models\User;

class BreedingRecordPolicy
{
    public function viewAny(User $user, Farm $farm): bool
    {
        return $user->canViewFarm($farm);
    }

    public function view(User $user, BreedingRecord $record): bool
    {
        return $user->canViewFarm($record->farm);
    }

    /**
     * Planning a breeding is a livestock-management decision.
     */
    public function create(User $user, Farm $farm): bool
    {
        return $user->canManageLivestock($farm);
    }

    public function update(User $user, BreedingRecord $record): bool
    {
        return $user->canManageLivestock($record->farm);
    }

    public function delete(User $user, BreedingRecord $record): bool
    {
        return $user->canManageLivestock($record->farm);
    }
}
