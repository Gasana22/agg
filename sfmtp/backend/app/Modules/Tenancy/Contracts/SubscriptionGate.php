<?php

namespace App\Modules\Tenancy\Contracts;

use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\Domain\Models\Organization;

/**
 * Billing rules that Tenancy must respect without depending on Billing
 * (docs/09-module-dependency-map.md). Billing binds the real implementation;
 * each method throws an ApiException when the action is not allowed.
 */
interface SubscriptionGate
{
    /** Plan farm limit (403 plan_limit_reached). */
    public function assertCanAddFarm(Organization $organization): void;

    /** Plan user limit, counted as distinct active users across the organization's farms. */
    public function assertCanAddMember(Farm $farm, string $userId): void;

    /** A suspended or cancelled subscription blocks farm access (403 subscription_suspended). */
    public function assertFarmUsable(Farm $farm): void;
}
