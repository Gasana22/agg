<?php

namespace App\Policies;

use App\Models\CropHarvest;
use App\Models\CropSale;
use App\Models\User;

class CropSalePolicy
{
    /**
     * Revenue data is financial information — managers only, same as
     * WorkerProfile pay rates and Attendance approval.
     */
    public function viewAny(User $user, CropHarvest $harvest): bool
    {
        return $user->canManageFarm($harvest->cropSeason->farm);
    }

    public function view(User $user, CropSale $sale): bool
    {
        return $user->canManageFarm($sale->cropHarvest->cropSeason->farm);
    }

    public function create(User $user, CropHarvest $harvest): bool
    {
        return $user->canManageFarm($harvest->cropSeason->farm);
    }

    public function update(User $user, CropSale $sale): bool
    {
        return $user->canManageFarm($sale->cropHarvest->cropSeason->farm);
    }

    public function delete(User $user, CropSale $sale): bool
    {
        return $user->canManageFarm($sale->cropHarvest->cropSeason->farm);
    }
}
