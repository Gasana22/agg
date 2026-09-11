<?php

namespace App\Policies;

use App\Models\Farm;
use App\Models\Supplier;
use App\Models\User;

class SupplierPolicy
{
    public function viewAny(User $user, Farm $farm): bool
    {
        return $user->canViewFarm($farm);
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $user->canViewFarm($supplier->farm);
    }

    public function create(User $user, Farm $farm): bool
    {
        return $user->canManageProcurement($farm);
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $user->canManageProcurement($supplier->farm);
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $user->canManageProcurement($supplier->farm);
    }
}
