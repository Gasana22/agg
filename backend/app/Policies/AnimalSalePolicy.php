<?php

namespace App\Policies;

use App\Models\Animal;
use App\Models\AnimalSale;
use App\Models\User;

class AnimalSalePolicy
{
    /**
     * Sale price is financial data — farm managers and the livestock
     * manager, same treatment as CropSale.
     */
    public function viewAny(User $user, Animal $animal): bool
    {
        return $user->canManageLivestock($animal->farm);
    }

    public function view(User $user, AnimalSale $sale): bool
    {
        return $user->canManageLivestock($sale->animal->farm);
    }

    public function create(User $user, Animal $animal): bool
    {
        return $user->canManageLivestock($animal->farm);
    }

    public function update(User $user, AnimalSale $sale): bool
    {
        return $user->canManageLivestock($sale->animal->farm);
    }

    public function delete(User $user, AnimalSale $sale): bool
    {
        return $user->canManageLivestock($sale->animal->farm);
    }
}
