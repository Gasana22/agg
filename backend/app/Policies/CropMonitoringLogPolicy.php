<?php

namespace App\Policies;

use App\Models\CropMonitoringLog;
use App\Models\CropSeason;
use App\Models\User;

class CropMonitoringLogPolicy
{
    public function viewAny(User $user, CropSeason $cropSeason): bool
    {
        return $user->canViewFarm($cropSeason->farm);
    }

    public function view(User $user, CropMonitoringLog $log): bool
    {
        return $user->canViewFarm($log->cropSeason->farm);
    }

    /**
     * Any farm member (an agronomist spotting disease, a field worker
     * noticing pests) can report a monitoring observation.
     */
    public function create(User $user, CropSeason $cropSeason): bool
    {
        return $user->canViewFarm($cropSeason->farm);
    }

    public function update(User $user, CropMonitoringLog $log): bool
    {
        return $user->canManageCrops($log->cropSeason->farm) || $log->reported_by === $user->id;
    }

    public function delete(User $user, CropMonitoringLog $log): bool
    {
        return $user->canManageCrops($log->cropSeason->farm);
    }
}
