<?php

namespace App\Policies;

use App\Models\Block;
use App\Models\Section;
use App\Models\User;

class SectionPolicy
{
    public function viewAny(User $user, Block $block): bool
    {
        return $user->canViewFarm($block->farm);
    }

    public function view(User $user, Section $section): bool
    {
        return $user->canViewFarm($section->block->farm);
    }

    public function create(User $user, Block $block): bool
    {
        return $user->canManageFarm($block->farm);
    }

    public function update(User $user, Section $section): bool
    {
        return $user->canManageFarm($section->block->farm);
    }

    public function delete(User $user, Section $section): bool
    {
        return $user->canManageFarm($section->block->farm);
    }
}
