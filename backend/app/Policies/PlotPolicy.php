<?php

namespace App\Policies;

use App\Models\Plot;
use App\Models\Section;
use App\Models\User;

class PlotPolicy
{
    public function viewAny(User $user, Section $section): bool
    {
        return $user->canViewFarm($section->block->farm);
    }

    public function view(User $user, Plot $plot): bool
    {
        return $user->canViewFarm($plot->section->block->farm);
    }

    public function create(User $user, Section $section): bool
    {
        return $user->canManageFarm($section->block->farm);
    }

    public function update(User $user, Plot $plot): bool
    {
        return $user->canManageFarm($plot->section->block->farm);
    }

    public function delete(User $user, Plot $plot): bool
    {
        return $user->canManageFarm($plot->section->block->farm);
    }
}
