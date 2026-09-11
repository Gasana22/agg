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

    /**
     * Viewing the Farm record itself (name, location, active status) is
     * system-administration territory as much as farm-operations — the
     * admin bypass is explicit here, unlike canViewFarm()/canManageFarm(),
     * which no longer bypass for operational data (crops, livestock,
     * finance, staff, ...).
     */
    public function view(User $user, Farm $farm): bool
    {
        return $user->isSystemAdministrator() || $user->canViewFarm($farm);
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
        return $user->isSystemAdministrator() || $user->canManageFarm($farm);
    }

    public function delete(User $user, Farm $farm): bool
    {
        return $user->isSystemAdministrator() || $user->canManageFarm($farm);
    }
}
