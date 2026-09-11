<?php

namespace App\Policies;

use App\Models\Farm;
use App\Models\TraceBatch;
use App\Models\User;

class TraceBatchPolicy
{
    public function viewAny(User $user, Farm $farm): bool
    {
        return $user->canViewFarm($farm);
    }

    public function view(User $user, TraceBatch $traceBatch): bool
    {
        return $user->canViewFarm($traceBatch->farm);
    }

    /**
     * Coarse gate: the caller must manage at least one production domain
     * on this farm. The controller checks the specific source record's
     * domain (crop vs livestock) once it knows which one is referenced.
     */
    public function create(User $user, Farm $farm): bool
    {
        return $user->canManageCrops($farm) || $user->canManageLivestock($farm);
    }
}
