<?php

namespace App\Modules\Billing\Application;

use App\Modules\Billing\Domain\Models\Subscription;
use App\Modules\Tenancy\Contracts\SubscriptionGate;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Support\Http\ApiException;

/**
 * Enforces plan limits and subscription state for Tenancy
 * (requirements §35: farm/user/storage limits; docs/02 §7 test 6).
 */
class PlanLimitGate implements SubscriptionGate
{
    public function __construct(private readonly Usage $usage) {}

    public function assertCanAddFarm(Organization $organization): void
    {
        $subscription = $this->subscription($organization->id);
        if ($subscription === null) {
            return;
        }
        $this->assertUsable($subscription);

        $limit = $subscription->plan->max_farms;
        $used = $this->usage->farms($organization->id);
        if ($limit !== null && $used >= $limit) {
            throw $this->limitReached('farms', $limit, $used, $subscription);
        }
    }

    public function assertCanAddMember(Farm $farm, string $userId): void
    {
        $subscription = $this->subscription($farm->organization_id);
        if ($subscription === null) {
            return;
        }
        $this->assertUsable($subscription);

        $limit = $subscription->plan->max_users;
        if ($limit === null || $this->usage->isCountedUser($farm->organization_id, $userId)) {
            return;   // already counted: joining another farm is free
        }

        $used = $this->usage->users($farm->organization_id);
        if ($used >= $limit) {
            throw $this->limitReached('users', $limit, $used, $subscription);
        }
    }

    public function assertFarmUsable(Farm $farm): void
    {
        $subscription = $this->subscription($farm->organization_id);
        if ($subscription !== null) {
            $this->assertUsable($subscription);
        }
    }

    private function assertUsable(Subscription $subscription): void
    {
        if (! $subscription->status->allowsAccess()) {
            throw new ApiException(403, 'subscription_suspended', 'The subscription for this farm is not active. The owner can renew it under Subscription.', [
                'subscription_status' => $subscription->status->value,
            ]);
        }
    }

    private function limitReached(string $resource, int $limit, int $used, Subscription $subscription): ApiException
    {
        return new ApiException(403, 'plan_limit_reached', "Your {$subscription->plan->name} plan allows {$limit} {$resource}. Upgrade to add more.", [
            'limit' => ['resource' => $resource, 'limit' => $limit, 'used' => $used, 'plan' => $subscription->plan->code],
        ]);
    }

    private function subscription(string $organizationId): ?Subscription
    {
        return Subscription::with('plan')->where('organization_id', $organizationId)->first();
    }
}
