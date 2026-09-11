<?php

namespace App\Policies;

use App\Models\PoultryFlock;
use App\Models\PoultrySale;
use App\Models\User;

class PoultrySalePolicy
{
    /**
     * Sale price is financial data — farm managers and the livestock
     * manager, same treatment as AnimalSalePolicy.
     */
    public function viewAny(User $user, PoultryFlock $flock): bool
    {
        return $user->canManageLivestock($flock->farm);
    }

    public function view(User $user, PoultrySale $sale): bool
    {
        return $user->canManageLivestock($sale->flock->farm);
    }

    public function create(User $user, PoultryFlock $flock): bool
    {
        return $user->canManageLivestock($flock->farm);
    }

    public function update(User $user, PoultrySale $sale): bool
    {
        return $user->canManageLivestock($sale->flock->farm);
    }

    public function delete(User $user, PoultrySale $sale): bool
    {
        return $user->canManageLivestock($sale->flock->farm);
    }
}
