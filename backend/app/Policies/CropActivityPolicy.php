<?php

namespace App\Policies;

use App\Models\CropActivity;
use App\Models\CropSeason;
use App\Models\User;

class CropActivityPolicy
{
    public function viewAny(User $user, CropSeason $cropSeason): bool
    {
        return $user->canViewFarm($cropSeason->farm);
    }

    public function view(User $user, CropActivity $activity): bool
    {
        return $user->canViewFarm($activity->cropSeason->farm);
    }

    /**
     * Field operations are typically logged by whoever did the work, not
     * just managers — any farm member can log an activity.
     */
    public function create(User $user, CropSeason $cropSeason): bool
    {
        return $user->canViewFarm($cropSeason->farm);
    }

    /**
     * The person who logged it can fix a typo; a manager can correct
     * anyone's entry.
     */
    public function update(User $user, CropActivity $activity): bool
    {
        return $user->canManageFarm($activity->cropSeason->farm) || $activity->performed_by === $user->id;
    }

    /**
     * Deleting a logged activity (and its cost record) is manager-only.
     */
    public function delete(User $user, CropActivity $activity): bool
    {
        return $user->canManageFarm($activity->cropSeason->farm);
    }
}
