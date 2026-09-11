<?php

namespace App\Policies;

use App\Models\PoultryFlock;
use App\Models\PoultryProductionRecord;
use App\Models\User;

class PoultryProductionRecordPolicy
{
    public function viewAny(User $user, PoultryFlock $flock): bool
    {
        return $user->canViewFarm($flock->farm);
    }

    public function view(User $user, PoultryProductionRecord $record): bool
    {
        return $user->canViewFarm($record->flock->farm);
    }

    /**
     * Whoever collects the eggs logs it — same treatment as
     * AnimalProductionRecordPolicy::create.
     */
    public function create(User $user, PoultryFlock $flock): bool
    {
        return $user->canViewFarm($flock->farm);
    }

    public function update(User $user, PoultryProductionRecord $record): bool
    {
        return $user->canManageLivestock($record->flock->farm) || $record->recorded_by === $user->id;
    }

    public function delete(User $user, PoultryProductionRecord $record): bool
    {
        return $user->canManageLivestock($record->flock->farm);
    }
}
