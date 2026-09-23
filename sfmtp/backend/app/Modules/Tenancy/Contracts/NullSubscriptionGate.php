<?php

namespace App\Modules\Tenancy\Contracts;

use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\Domain\Models\Organization;

class NullSubscriptionGate implements SubscriptionGate
{
    public function assertCanAddFarm(Organization $organization): void {}

    public function assertCanAddMember(Farm $farm, string $userId): void {}

    public function assertFarmUsable(Farm $farm): void {}
}
