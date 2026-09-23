<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Application\SubscriptionService;
use App\Modules\Billing\Domain\Models\Plan;
use App\Modules\Billing\Domain\Models\Subscription;
use App\Modules\Billing\Http\Resources\PlanResource;
use App\Modules\Billing\Http\Resources\SubscriptionResource;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Support\Http\ApiException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The Farm Owner manages their organization's subscription (requirements §8).
 * Deliberately outside /farms/{farm}: it keeps working while farms are
 * suspended for non-payment, so the owner can renew.
 */
class OwnerBillingController
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function plans(): AnonymousResourceCollection
    {
        return PlanResource::collection(Plan::where('is_active', true)->where('is_public', true)->orderBy('sort_order')->get());
    }

    public function show(Request $request): SubscriptionResource
    {
        return new SubscriptionResource($this->subscription($request)->load(['plan', 'organization', 'payments', 'history']));
    }

    public function changePlan(Request $request): SubscriptionResource
    {
        $data = $request->validate(['plan_code' => ['required', 'string', 'max:40']]);
        $plan = Plan::where('code', $data['plan_code'])->first()
            ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['plan_code' => ['Unknown plan.']]);

        $subscription = $this->subscriptions->changePlan($this->subscription($request), $plan, $request->user(), byOwner: true);

        return new SubscriptionResource($subscription->load(['plan', 'organization']));
    }

    public function cancel(Request $request): SubscriptionResource
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        // Owners cancel at the end of the paid period; immediate cancellation is an admin action.
        return new SubscriptionResource($this->subscriptions->cancel($this->subscription($request), true, $request->user(), $data['reason'] ?? null)->load(['plan', 'organization']));
    }

    public function resume(Request $request): SubscriptionResource
    {
        return new SubscriptionResource($this->subscriptions->resume($this->subscription($request), $request->user())->load(['plan', 'organization']));
    }

    private function subscription(Request $request): Subscription
    {
        $organization = Organization::where('owner_user_id', $request->user()->id)->first()
            ?? throw ApiException::forbidden('billing_owner_only', 'Only a farm owner can manage a subscription.');

        return Subscription::with('plan')->where('organization_id', $organization->id)->first()
            ?? throw ApiException::notFound();
    }
}
