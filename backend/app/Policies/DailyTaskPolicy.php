<?php

namespace App\Policies;

use App\Models\DailyTask;
use App\Models\Farm;
use App\Models\User;
use App\Models\WorkerProfile;

class DailyTaskPolicy
{
    public function viewAny(User $user, Farm $farm): bool
    {
        return $user->canViewFarm($farm);
    }

    public function view(User $user, DailyTask $task): bool
    {
        return $task->assigned_to === $user->id
            || $task->assigned_by === $user->id
            || $user->canManageFarm($task->farm);
    }

    /**
     * A farm manager can assign to anyone on the farm; a supervisor can
     * assign tasks to their own supervisees without being a full manager.
     */
    public function create(User $user, Farm $farm, ?WorkerProfile $assigneeProfile = null): bool
    {
        if ($user->canManageFarm($farm)) {
            return true;
        }

        return $assigneeProfile !== null && $user->isSupervisorOf($assigneeProfile);
    }

    /**
     * Editing title/description/due date — the assigner or a manager.
     */
    public function update(User $user, DailyTask $task): bool
    {
        return $task->assigned_by === $user->id || $user->canManageFarm($task->farm);
    }

    /**
     * Moving the task through pending/ongoing/completed — the worker
     * doing it, the person who assigned it, or a manager.
     */
    public function updateStatus(User $user, DailyTask $task): bool
    {
        return $task->assigned_to === $user->id
            || $task->assigned_by === $user->id
            || $user->canManageFarm($task->farm);
    }

    public function delete(User $user, DailyTask $task): bool
    {
        return $task->assigned_by === $user->id || $user->canManageFarm($task->farm);
    }
}
