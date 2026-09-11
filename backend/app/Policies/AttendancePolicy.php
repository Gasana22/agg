<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;
use App\Models\WorkerProfile;

class AttendancePolicy
{
    public function viewAny(User $user, WorkerProfile $profile): bool
    {
        return $this->canSee($user, $profile);
    }

    public function view(User $user, Attendance $attendance): bool
    {
        return $this->canSee($user, $attendance->workerProfile);
    }

    public function update(User $user, Attendance $attendance): bool
    {
        return $user->canManageFarm($attendance->workerProfile->farm);
    }

    /**
     * "Supervisor Approval": the farm's managers, or the specific
     * supervisor assigned to this worker.
     */
    public function approve(User $user, Attendance $attendance): bool
    {
        $profile = $attendance->workerProfile;

        return $user->canManageFarm($profile->farm) || $user->isSupervisorOf($profile);
    }

    private function canSee(User $user, WorkerProfile $profile): bool
    {
        return $user->canManageFarm($profile->farm)
            || $profile->user_id === $user->id
            || $user->isSupervisorOf($profile);
    }
}
