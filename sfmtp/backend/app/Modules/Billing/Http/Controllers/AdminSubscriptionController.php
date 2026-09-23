<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Application\SubscriptionService;
use App\Modules\Billing\Domain\Enums\SubscriptionStatus;
use App\Modules\Billing\Domain\Models\Plan;
use App\Modules\Billing\Domain\Models\Subscription;
use App\Modules\Billing\Http\Resources\SubscriptionResource;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AdminSubscriptionController
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.status' => ['sometimes', Rule::in(SubscriptionStatus::values())],
            'filter.expiring_within_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $filter = $data['filter'] ?? [];

        $page = Subscription::with(['plan', 'organization.owner'])
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(isset($filter['expiring_within_days']), fn ($q) => $q
                ->whereIn('status', [SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value])
                ->whereDate('current_period_end', '<=', now()->addDays((int) $filter['expiring_within_days'])))
            ->when($data['q'] ?? null, fn ($q, $v) => $q->whereHas('organization', fn ($o) => $o->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($v).'%'])))
            ->orderBy('current_period_end')
            ->orderBy('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 25));

        return SubscriptionResource::collection($page);
    }

    public function show(Subscription $subscription): SubscriptionResource
    {
        return new SubscriptionResource($subscription->load(['plan', 'organization.owner', 'payments', 'history']));
    }

    public function recordPayment(Request $request, Subscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'provider' => ['required', Rule::in(['manual', 'bank_transfer', 'mobile_money', 'cash'])],
            'provider_ref' => ['required', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['succeeded', 'failed'])],
            'paid_at' => ['sometimes', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $data['currency'] = strtoupper($data['currency']);

        $payment = $this->subscriptions->recordPayment($subscription->load('plan'), $data, $request->user());

        return (new SubscriptionResource($subscription->refresh()->load(['plan', 'organization.owner', 'payments', 'history'])))
            ->additional(['meta' => ['payment_id' => $payment->id]])
            ->response()
            ->setStatusCode(201);
    }

    public function changePlan(Request $request, Subscription $subscription): SubscriptionResource
    {
        $data = $request->validate(['plan_code' => ['required', 'string', 'max:40']]);
        $plan = Plan::where('code', $data['plan_code'])->first()
            ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['plan_code' => ['Unknown plan.']]);

        return new SubscriptionResource($this->subscriptions->changePlan($subscription, $plan, $request->user(), byOwner: false)->load(['organization.owner']));
    }

    public function cancel(Request $request, Subscription $subscription): SubscriptionResource
    {
        $data = $request->validate([
            'at_period_end' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return new SubscriptionResource($this->subscriptions->cancel($subscription, $data['at_period_end'], $request->user(), $data['reason'])->load(['plan', 'organization.owner']));
    }

    public function extend(Request $request, Subscription $subscription): SubscriptionResource
    {
        $data = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:90'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return new SubscriptionResource($this->subscriptions->extend($subscription, $data['days'], $request->user(), $data['reason'])->load(['plan', 'organization.owner']));
    }
}
