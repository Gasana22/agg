<?php

namespace App\Policies;

use App\Models\Delivery;
use App\Models\Farm;
use App\Models\User;

class DeliveryPolicy
{
    public function viewAny(User $user, Farm $farm): bool
    {
        return $user->canViewFarm($farm);
    }

    public function view(User $user, Delivery $delivery): bool
    {
        return $user->canViewFarm($delivery->purchaseOrder->farm);
    }

    public function create(User $user, Farm $farm): bool
    {
        return $user->canViewFarm($farm);
    }

    public function update(User $user, Delivery $delivery): bool
    {
        return $user->canManageProcurement($delivery->purchaseOrder->farm);
    }

    public function delete(User $user, Delivery $delivery): bool
    {
        return $user->canManageProcurement($delivery->purchaseOrder->farm);
    }
}
