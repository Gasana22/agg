<?php

namespace App\Policies;

use App\Models\Farm;
use App\Models\User;

class FarmPolicy
{
    /**
     * Listing is always allowed; the controller scopes the query to farms
     * the user can see (all of them for system_administrator, only their
     * own memberships otherwise).
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Farm $farm): bool
    {
        return $user->canViewFarm($farm);
    }

    /**
     * Any authenticated user may create a farm and becomes its owner
     * (Farm::booted() auto-attaches them as farm_owner).
     */
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Farm $farm): bool
    {
        return $user->canManageFarm($farm);
    }

    public function delete(User $user, Farm $farm): bool
    {
        return $user->canManageFarm($farm);
    }
}
