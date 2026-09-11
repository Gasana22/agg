<?php

namespace App\Policies;

use App\Models\PayrollPayment;
use App\Models\User;
use App\Models\WorkerProfile;

class PayrollPaymentPolicy
{
    public function viewAny(User $user, WorkerProfile $profile): bool
    {
        return $this->canSee($user, $profile);
    }

    /**
     * A worker can see their own pay history, same as viewing their own
     * WorkerProfile.
     */
    public function view(User $user, PayrollPayment $payment): bool
    {
        return $this->canSee($user, $payment->workerProfile);
    }

    /**
     * Running payroll and marking it paid is a management action.
     */
    public function create(User $user, WorkerProfile $profile): bool
    {
        return $user->canManageFarm($profile->farm);
    }

    public function update(User $user, PayrollPayment $payment): bool
    {
        return $user->canManageFarm($payment->farm);
    }

    public function delete(User $user, PayrollPayment $payment): bool
    {
        return $user->canManageFarm($payment->farm);
    }

    private function canSee(User $user, WorkerProfile $profile): bool
    {
        return $user->canManageFarm($profile->farm) || $profile->user_id === $user->id;
    }
}
