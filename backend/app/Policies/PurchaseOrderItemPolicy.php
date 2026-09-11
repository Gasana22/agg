<?php

namespace App\Policies;

use App\Models\PurchaseOrderItem;
use App\Models\User;

class PurchaseOrderItemPolicy
{
    public function update(User $user, PurchaseOrderItem $purchaseOrderItem): bool
    {
        return $user->canManageProcurement($purchaseOrderItem->purchaseOrder->farm);
    }

    public function delete(User $user, PurchaseOrderItem $purchaseOrderItem): bool
    {
        return $user->canManageProcurement($purchaseOrderItem->purchaseOrder->farm);
    }
}
