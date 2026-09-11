<?php

namespace App\Policies;

use App\Models\Farm;
use App\Models\InventoryTransaction;
use App\Models\User;

class InventoryTransactionPolicy
{
    public function viewAny(User $user, Farm $farm): bool
    {
        return $user->canViewFarm($farm);
    }

    public function view(User $user, InventoryTransaction $inventoryTransaction): bool
    {
        return $user->canViewFarm($inventoryTransaction->farm);
    }

    public function create(User $user, Farm $farm): bool
    {
        return $user->canManageInventory($farm);
    }

    public function update(User $user, InventoryTransaction $inventoryTransaction): bool
    {
        return $user->canManageInventory($inventoryTransaction->farm);
    }

    public function delete(User $user, InventoryTransaction $inventoryTransaction): bool
    {
        return $user->canManageInventory($inventoryTransaction->farm);
    }
}
