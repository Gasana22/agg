<?php

namespace App\Policies;

use App\Models\Block;
use App\Models\Farm;
use App\Models\User;

class BlockPolicy
{
    public function viewAny(User $user, Farm $farm): bool
    {
        return $user->canViewFarm($farm);
    }

    public function view(User $user, Block $block): bool
    {
        return $user->canViewFarm($block->farm);
    }

    public function create(User $user, Farm $farm): bool
    {
        return $user->canManageFarm($farm);
    }

    public function update(User $user, Block $block): bool
    {
        return $user->canManageFarm($block->farm);
    }

    public function delete(User $user, Block $block): bool
    {
        return $user->canManageFarm($block->farm);
    }
}
