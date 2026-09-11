<?php

namespace App\Policies;

use App\Models\TraceBatch;
use App\Models\User;

class TraceEventPolicy
{
    public function viewAny(User $user, TraceBatch $traceBatch): bool
    {
        return $user->canViewFarm($traceBatch->farm);
    }

    /**
     * Adding a chain-of-custody event (processed, shipped, sold, recalled)
     * is restricted to whoever can manage the batch's production domain —
     * this is a public-facing record, not a casual field log.
     */
    public function create(User $user, TraceBatch $traceBatch): bool
    {
        return $traceBatch->canBeManagedBy($user);
    }
}
