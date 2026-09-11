<?php

namespace App\Policies;

use App\Models\Farm;
use App\Models\PurchaseOrder;
use App\Models\User;

class PurchaseOrderPolicy
{
    public function viewAny(User $user, Farm $farm): bool
    {
        return $user->canViewFarm($farm);
    }

    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->canViewFarm($purchaseOrder->farm);
    }

    public function create(User $user, Farm $farm): bool
    {
        return $user->canManageProcurement($farm);
    }

    public function update(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->canManageProcurement($purchaseOrder->farm);
    }

    public function delete(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->canManageProcurement($purchaseOrder->farm);
    }
}
