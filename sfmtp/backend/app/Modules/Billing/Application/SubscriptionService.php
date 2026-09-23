<?php

namespace App\Modules\Billing\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Billing\Domain\Enums\SubscriptionStatus;
use App\Modules\Billing\Domain\Models\Plan;
use App\Modules\Billing\Domain\Models\Subscription;
use App\Modules\Billing\Domain\Models\SubscriptionHistory;
use App\Modules\Billing\Domain\Models\SubscriptionPayment;
use App\Modules\Billing\Notifications\SubscriptionNotice;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Support\Http\ApiException;
use App\Support\Settings\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Subscription lifecycle (requirements §35):
 *
 *   trialing ──payment──▶ active ──period ends──▶ grace ──grace ends──▶ suspended
 *        │                  ▲  │                    │                     │
 *        └──period ends─────┼──┘◀──────payment──────┴──────payment────────┘
 *                           cancel (now, or at period end) ──▶ cancelled
 *
 * Periods are whole UTC dates, inclusive. Every change writes history and audit.
 */
class SubscriptionService
{
    public function __construct(
        private readonly PlatformSettings $settings,
        private readonly Usage $usage,
        private readonly AuditLogger $audit,
    ) {}

    public function startTrial(Organization $organization): ?Subscription
    {
        $plan = Plan::where('code', $this->settings->get('billing.default_plan_code'))->where('is_active', true)->first()
            ?? Plan::where('is_active', true)->where('is_public', true)->orderBy('sort_order')->first();

        if ($plan === null) {
            Log::warning('No active plan: organization created without a subscription.', ['organization_id' => $organization->id]);

            return null;
        }

        $days = $plan->trial_days ?? (int) $this->settings->get('billing.trial_days');
        $today = CarbonImmutable::today('UTC');

        $subscription = Subscription::create([
            'organization_id' => $organization->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Trialing,
            'current_period_start' => $today,
            'current_period_end' => $today->addDays(max($days, 1) - 1),
        ]);
        $this->history($subscription, null, 'trial_started', ['plan' => $plan->code, 'days' => $days]);

        return $subscription;
    }

    /**
     * Record a payment. A successful one (at least the plan price) starts or
     * extends a paid period and restores access.
     */
    public function recordPayment(Subscription $subscription, array $data, ?User $actor): SubscriptionPayment
    {
        $plan = $subscription->plan;
        $status = $data['status'] ?? 'succeeded';

        if ($data['currency'] !== $plan->currency) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['currency' => ["Payments for this plan must be in {$plan->currency}."]]);
        }
        if ($status === 'succeeded' && (float) $data['amount'] < (float) $plan->price) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['amount' => ["The {$plan->name} plan costs {$plan->price} {$plan->currency} per period."]]);
        }
        if (SubscriptionPayment::where('provider', $data['provider'])->where('provider_ref', $data['provider_ref'])->exists()) {
            throw ApiException::conflict('duplicate', 'This payment reference was already recorded.');
        }

        return DB::transaction(function () use ($subscription, $data, $status, $actor, $plan) {
            $subscription = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $payment = new SubscriptionPayment([
                'subscription_id' => $subscription->id,
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'provider' => $data['provider'],
                'provider_ref' => $data['provider_ref'],
                'status' => $status,
                'paid_at' => $status === 'succeeded' ? ($data['paid_at'] ?? now()) : null,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $actor?->id,
            ]);

            if ($status !== 'succeeded') {
                $payment->save();
                $this->history($subscription, $subscription->status, 'payment_failed', ['payment_id' => $payment->id], $actor);
                $this->audit->record('billing.payment_failed', $subscription, null, ['payment_id' => $payment->id, 'amount' => $data['amount']], ['farm_id' => null]);

                return $payment;
            }

            $today = CarbonImmutable::today('UTC');
            $currentEnd = CarbonImmutable::parse($subscription->current_period_end);
            // Paying early while active extends the current period; otherwise a new period starts today.
            $start = $subscription->status === SubscriptionStatus::Active && $currentEnd->gte($today)
                ? $currentEnd->addDay()
                : $today;
            $end = ($plan->billing_period === 'yearly' ? $start->addYearNoOverflow() : $start->addMonthNoOverflow())->subDay();

            $payment->fill(['period_start' => $start, 'period_end' => $end])->save();

            $from = $subscription->status;
            $subscription->fill([
                'status' => SubscriptionStatus::Active,
                'current_period_start' => $from === SubscriptionStatus::Active ? $subscription->current_period_start : $start,
                'current_period_end' => $end,
                'grace_until' => null,
                'cancel_at_period_end' => false,
                'cancelled_at' => null,
                'suspended_at' => null,
            ])->save();

            $this->history($subscription, $from, 'payment_received', ['payment_id' => $payment->id, 'period_end' => $end->toDateString()], $actor);
            $this->audit->record('billing.payment_received', $subscription, ['status' => $from->value], [
                'status' => 'active', 'payment_id' => $payment->id, 'amount' => $data['amount'], 'period_end' => $end->toDateString(),
            ], ['farm_id' => null]);
            $this->notifyOwner($subscription, 'payment_received');

            return $payment;
        });
    }

    /**
     * @param  bool  $byOwner  owners may only pick public plans
     */
    public function changePlan(Subscription $subscription, Plan $plan, ?User $actor, bool $byOwner): Subscription
    {
        if (! $plan->is_active || ($byOwner && ! $plan->is_public)) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['plan_code' => ['This plan is not available.']]);
        }
        if ($plan->id === $subscription->plan_id) {
            throw ApiException::conflict('invalid_state_transition', 'The subscription is already on this plan.');
        }

        $orgId = $subscription->organization_id;
        $errors = [];
        if ($plan->max_farms !== null && ($used = $this->usage->farms($orgId)) > $plan->max_farms) {
            $errors['plan_code'][] = "This plan allows {$plan->max_farms} farms; you have {$used}. Close farms first.";
        }
        if ($plan->max_users !== null && ($used = $this->usage->users($orgId)) > $plan->max_users) {
            $errors['plan_code'][] = "This plan allows {$plan->max_users} users; you have {$used}.";
        }
        if ($errors) {
            throw ApiException::unprocessable('plan_limit_exceeded', 'Current usage is above this plan\'s limits.', $errors);
        }

        $old = $subscription->plan;
        $subscription->plan()->associate($plan)->save();
        $this->history($subscription, $subscription->status, 'plan_changed', ['from' => $old->code, 'to' => $plan->code], $actor);
        $this->audit->record('billing.plan_changed', $subscription, ['plan' => $old->code], ['plan' => $plan->code], ['farm_id' => null]);

        return $subscription->load('plan');
    }

    public function cancel(Subscription $subscription, bool $atPeriodEnd, ?User $actor, ?string $reason): Subscription
    {
        if ($subscription->status === SubscriptionStatus::Cancelled) {
            throw ApiException::conflict('invalid_state_transition', 'The subscription is already cancelled.');
        }

        if ($atPeriodEnd) {
            $subscription->forceFill(['cancel_at_period_end' => true])->save();
            $this->history($subscription, $subscription->status, 'cancel_scheduled', array_filter(['reason' => $reason]), $actor);
        } else {
            $this->transition($subscription, SubscriptionStatus::Cancelled, 'cancelled', ['cancelled_at' => now()], array_filter(['reason' => $reason]), $actor);
        }
        $this->audit->record('billing.subscription_cancelled', $subscription, null, array_filter(['at_period_end' => $atPeriodEnd, 'reason' => $reason]), ['farm_id' => null]);

        return $subscription;
    }

    public function resume(Subscription $subscription, ?User $actor): Subscription
    {
        if (! $subscription->cancel_at_period_end) {
            throw ApiException::conflict('invalid_state_transition', 'No cancellation is scheduled.');
        }
        $subscription->forceFill(['cancel_at_period_end' => false])->save();
        $this->history($subscription, $subscription->status, 'cancel_withdrawn', null, $actor);

        return $subscription;
    }

    /** Support/billing goodwill: push the current period (trial or paid) out by N days. */
    public function extend(Subscription $subscription, int $days, User $actor, string $reason): Subscription
    {
        if (! in_array($subscription->status, [SubscriptionStatus::Trialing, SubscriptionStatus::Active], true)) {
            throw ApiException::conflict('invalid_state_transition', 'Only trialing or active subscriptions can be extended. Record a payment instead.');
        }
        $end = CarbonImmutable::parse($subscription->current_period_end)->addDays($days);
        $subscription->forceFill(['current_period_end' => $end])->save();
        $this->history($subscription, $subscription->status, 'period_extended', ['days' => $days, 'reason' => $reason, 'period_end' => $end->toDateString()], $actor);
        $this->audit->record('billing.period_extended', $subscription, null, ['days' => $days, 'reason' => $reason], ['farm_id' => null]);

        return $subscription;
    }

    /**
     * Daily lifecycle step (billing:advance-subscriptions).
     *
     * @return array{grace:int, suspended:int, cancelled:int}
     */
    public function advance(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today('UTC');
        $graceDays = (int) $this->settings->get('billing.grace_days');
        $counts = ['grace' => 0, 'suspended' => 0, 'cancelled' => 0];

        $ended = Subscription::with('plan')
            ->whereIn('status', [SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value])
            ->whereDate('current_period_end', '<', $today)
            ->orderBy('id')
            ->get();

        foreach ($ended as $s) {
            if ($s->cancel_at_period_end) {
                $this->transition($s, SubscriptionStatus::Cancelled, 'cancelled', ['cancelled_at' => now()], ['reason' => 'scheduled']);
                $counts['cancelled']++;
            } elseif ($graceDays === 0) {
                $this->transition($s, SubscriptionStatus::Suspended, 'suspended', ['suspended_at' => now()]);
                $this->notifyOwner($s, 'suspended');
                $counts['suspended']++;
            } else {
                $graceUntil = CarbonImmutable::parse($s->current_period_end)->addDays($graceDays);
                $this->transition($s, SubscriptionStatus::Grace, 'grace_started', ['grace_until' => $graceUntil], ['grace_until' => $graceUntil->toDateString()]);
                $this->notifyOwner($s, 'grace_started');
                $counts['grace']++;
            }
        }

        $expired = Subscription::with('plan')
            ->where('status', SubscriptionStatus::Grace->value)
            ->whereDate('grace_until', '<', $today)
            ->orderBy('id')
            ->get();

        foreach ($expired as $s) {
            $this->transition($s, SubscriptionStatus::Suspended, 'suspended', ['suspended_at' => now()]);
            $this->notifyOwner($s, 'suspended');
            $counts['suspended']++;
        }

        return $counts;
    }

    private function transition(Subscription $s, SubscriptionStatus $to, string $event, array $attributes = [], ?array $details = null, ?User $actor = null): void
    {
        DB::transaction(function () use ($s, $to, $event, $attributes, $details, $actor) {
            $from = $s->status;
            $s->forceFill(['status' => $to] + $attributes)->save();
            $this->history($s, $from, $event, $details, $actor);
        });
    }

    private function history(Subscription $s, ?SubscriptionStatus $from, string $event, ?array $details = null, ?User $actor = null): void
    {
        SubscriptionHistory::create([
            'subscription_id' => $s->id,
            'from_status' => $from?->value,
            'to_status' => $s->status->value,
            'event' => $event,
            'details' => $details,
            'changed_by' => $actor?->id,
            'created_at' => now(),
        ]);
    }

    private function notifyOwner(Subscription $s, string $event): void
    {
        $s->loadMissing('organization.owner', 'plan');
        $s->organization?->owner?->notify(new SubscriptionNotice($s, $event));
    }
}
