<?php

namespace App\Policies;

use App\Models\Farm;
use App\Models\InventoryItem;
use App\Models\User;

class InventoryItemPolicy
{
    public function viewAny(User $user, Farm $farm): bool
    {
        return $user->canViewFarm($farm);
    }

    public function view(User $user, InventoryItem $inventoryItem): bool
    {
        return $user->canViewFarm($inventoryItem->farm);
    }

    public function create(User $user, Farm $farm): bool
    {
        return $user->canManageInventory($farm);
    }

    public function update(User $user, InventoryItem $inventoryItem): bool
    {
        return $user->canManageInventory($inventoryItem->farm);
    }

    public function delete(User $user, InventoryItem $inventoryItem): bool
    {
        return $user->canManageInventory($inventoryItem->farm);
    }
}
