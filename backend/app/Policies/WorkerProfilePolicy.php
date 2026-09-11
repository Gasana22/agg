<?php

namespace App\Policies;

use App\Models\Farm;
use App\Models\User;
use App\Models\WorkerProfile;

class WorkerProfilePolicy
{
    /**
     * Listing all profiles on a farm exposes pay rates — managers only.
     * A worker sees their own profile through view(), not this list.
     */
    public function viewAny(User $user, Farm $farm): bool
    {
        return $user->canManageFarm($farm);
    }

    public function view(User $user, WorkerProfile $profile): bool
    {
        return $user->canManageFarm($profile->farm)
            || $profile->user_id === $user->id
            || $user->isSupervisorOf($profile);
    }

    public function create(User $user, Farm $farm): bool
    {
        return $user->canManageFarm($farm);
    }

    /**
     * Rate, employee ID, supervisor — manager-only, not self-editable.
     */
    public function update(User $user, WorkerProfile $profile): bool
    {
        return $user->canManageFarm($profile->farm);
    }

    public function delete(User $user, WorkerProfile $profile): bool
    {
        return $user->canManageFarm($profile->farm);
    }

    /**
     * Checking in/out is self-service: a worker logs their own attendance.
     * A manager correcting someone else's record goes through Attendance's
     * update() instead.
     */
    public function checkInOut(User $user, WorkerProfile $profile): bool
    {
        return $profile->user_id === $user->id;
    }
}
