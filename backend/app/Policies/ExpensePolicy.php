<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\Farm;
use App\Models\User;

class ExpensePolicy
{
    public function viewAny(User $user, Farm $farm): bool
    {
        return $user->canManageFarm($farm);
    }

    public function view(User $user, Expense $expense): bool
    {
        return $user->canManageFarm($expense->farm);
    }

    public function create(User $user, Farm $farm): bool
    {
        return $user->canManageFarm($farm);
    }

    public function update(User $user, Expense $expense): bool
    {
        return $user->canManageFarm($expense->farm);
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $user->canManageFarm($expense->farm);
    }
}
