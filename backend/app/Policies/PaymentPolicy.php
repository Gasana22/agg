<?php

namespace App\Policies;

use App\Models\Farm;
use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function viewAny(User $user, Farm $farm): bool
    {
        return $user->canManageFinance($farm);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->canManageFinance($payment->purchaseOrder->farm);
    }

    public function create(User $user, Farm $farm): bool
    {
        return $user->canManageFinance($farm);
    }

    public function update(User $user, Payment $payment): bool
    {
        return $user->canManageFinance($payment->purchaseOrder->farm);
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $user->canManageFinance($payment->purchaseOrder->farm);
    }
}
