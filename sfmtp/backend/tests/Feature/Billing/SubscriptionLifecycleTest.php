<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Application\SubscriptionService;
use App\Modules\Billing\Domain\Models\Subscription;
use App\Modules\Billing\Domain\Models\SubscriptionHistory;
use App\Modules\Billing\Notifications\SubscriptionNotice;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Support\Database\AppendOnlyViolation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SubscriptionLifecycleTest extends TestCase
{
    private Farm $farm;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(now()->setDate(2026, 3, 1)->startOfDay());
        $this->farm = $this->farm();
        $this->subscription = Subscription::where('organization_id', $this->farm->organization_id)->firstOrFail();
    }

    private function advanceTo(string $date): void
    {
        $this->travelTo(CarbonImmutable::parse($date)->setTime(1, 0));
        $this->artisan('billing:advance-subscriptions')->assertSuccessful();
        $this->subscription->refresh();
    }

    private function pay(array $overrides = [])
    {
        return $this->asUser($this->platformAdmin(['billing']))->postJson("/api/v1/admin/subscriptions/{$this->subscription->id}/payments", $overrides + [
            'amount' => 250000, 'currency' => 'UGX', 'provider' => 'mobile_money', 'provider_ref' => 'MM-'.uniqid(),
        ]);
    }

    private function farmResponse()
    {
        return $this->asUser($this->ownerOf($this->farm))->getJson("/api/v1/farms/{$this->farm->id}");
    }

    public function test_a_new_organization_starts_a_trial_on_the_default_plan(): void
    {
        $this->assertSame('trialing', $this->subscription->status->value);
        $this->assertSame('growth', $this->subscription->plan->code);
        $this->assertSame('2026-03-01', $this->subscription->current_period_start->toDateString());
        $this->assertSame('2026-03-30', $this->subscription->current_period_end->toDateString());   // 30 days inclusive
        $this->assertSame('trial_started', SubscriptionHistory::where('subscription_id', $this->subscription->id)->value('event'));
    }

    public function test_an_unpaid_trial_goes_to_grace_then_suspension_and_payment_restores_access(): void
    {
        $this->advanceTo('2026-03-30');
        $this->assertSame('trialing', $this->subscription->status->value);

        $this->advanceTo('2026-03-31');
        $this->assertSame('grace', $this->subscription->status->value);
        $this->assertSame('2026-04-06', $this->subscription->grace_until->toDateString());
        Notification::assertSentTo($this->ownerOf($this->farm), SubscriptionNotice::class, fn ($n) => $n->event === 'grace_started');
        $this->farmResponse()->assertOk();   // grace keeps farms open

        $ws = $this->asUser($this->ownerOf($this->farm))->getJson('/api/v1/me/workspaces')->json('data.0.subscription');
        $this->assertSame(['status' => 'grace', 'current_period_end' => '2026-03-30', 'grace_until' => '2026-04-06', 'cancel_at_period_end' => false], $ws);

        $this->advanceTo('2026-04-07');
        $this->assertSame('suspended', $this->subscription->status->value);
        $this->assertProblem($this->farmResponse(), 403, 'subscription_suspended');

        // The owner can still reach billing to renew.
        $this->asUser($this->ownerOf($this->farm))->getJson('/api/v1/billing/subscription')->assertOk()->assertJsonPath('data.status', 'suspended');

        $this->pay()->assertCreated()->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.current_period_start', '2026-04-07')
            ->assertJsonPath('data.current_period_end', '2026-05-06');
        $this->farmResponse()->assertOk();
    }

    public function test_paying_early_extends_the_current_period(): void
    {
        $this->pay();   // converts the trial: 2026-03-01 … 2026-03-31
        $this->subscription->refresh();
        $this->assertSame('2026-03-31', $this->subscription->current_period_end->toDateString());

        $this->pay()->assertCreated()->assertJsonPath('data.current_period_end', '2026-04-30');
    }

    public function test_payments_are_validated_and_references_are_unique(): void
    {
        $this->pay(['amount' => 1000])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->pay(['currency' => 'USD'])->assertUnprocessable()->assertJsonValidationErrors('currency');

        $this->pay(['provider_ref' => 'MM-1'])->assertCreated();
        $this->assertProblem($this->pay(['provider_ref' => 'MM-1']), 409, 'duplicate');

        $this->pay(['status' => 'failed', 'amount' => 0, 'provider_ref' => 'MM-2'])->assertCreated()->assertJsonPath('data.status', 'active');
        $this->assertSame('payment_failed', SubscriptionHistory::where('subscription_id', $this->subscription->id)->latest('created_at')->value('event'));
    }

    public function test_scheduled_cancellation_ends_access_at_period_end_unless_withdrawn(): void
    {
        $owner = $this->ownerOf($this->farm);
        $this->asUser($owner)->postJson('/api/v1/billing/subscription/cancel', ['reason' => 'Season over'])->assertOk()->assertJsonPath('data.cancel_at_period_end', true);
        $this->asUser($owner)->postJson('/api/v1/billing/subscription/resume')->assertOk()->assertJsonPath('data.cancel_at_period_end', false);
        $this->asUser($owner)->postJson('/api/v1/billing/subscription/cancel')->assertOk();

        $this->advanceTo('2026-03-31');
        $this->assertSame('cancelled', $this->subscription->status->value);
        $this->assertProblem($this->farmResponse(), 403, 'subscription_suspended');
    }

    public function test_admins_can_extend_a_trial_and_cancel_immediately(): void
    {
        $billing = $this->platformAdmin(['billing']);

        $this->asUser($billing)->postJson("/api/v1/admin/subscriptions/{$this->subscription->id}/extend", ['days' => 14, 'reason' => 'Onboarding delay'])
            ->assertOk()->assertJsonPath('data.current_period_end', '2026-04-13');
        $this->asUser($billing)->postJson("/api/v1/admin/subscriptions/{$this->subscription->id}/cancel", ['at_period_end' => false, 'reason' => 'Fraud'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertProblem($this->asUser($billing)->postJson("/api/v1/admin/subscriptions/{$this->subscription->id}/extend", ['days' => 1, 'reason' => 'x']), 409, 'invalid_state_transition');
    }

    public function test_zero_grace_days_suspends_straight_away(): void
    {
        $this->asUser($this->platformAdmin())->putJson('/api/v1/admin/settings', ['settings' => ['billing.grace_days' => 0]])->assertOk();

        $this->advanceTo('2026-03-31');
        $this->assertSame('suspended', $this->subscription->status->value);
    }

    public function test_admin_subscription_views_include_history_and_payments(): void
    {
        $this->travel(1)->seconds();   // the clock is frozen in setUp
        $this->pay(['provider_ref' => 'BANK-77']);
        $viewer = $this->platformAdmin(['support']);

        $this->asUser($viewer)->getJson('/api/v1/admin/subscriptions?filter[status]=active')->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($viewer)->getJson("/api/v1/admin/subscriptions/{$this->subscription->id}")->assertOk()
            ->assertJsonPath('data.payments.0.provider_ref', 'BANK-77')
            ->assertJsonPath('data.history.0.event', 'payment_received')
            ->assertJsonPath('data.usage.farms', ['used' => 1, 'limit' => 5]);
    }

    public function test_billing_history_and_payments_cannot_be_rewritten(): void
    {
        $this->pay();

        try {
            SubscriptionHistory::first()->update(['event' => 'x']);
            $this->fail('History update allowed');
        } catch (AppendOnlyViolation) {
        }

        $this->expectException(QueryException::class);
        DB::table('subscription_payments')->delete();
    }

    public function test_mrr_and_revenue_appear_on_the_admin_dashboard(): void
    {
        $this->pay();

        $kpis = collect($this->asUser($this->platformAdmin())->getJson('/api/v1/admin/dashboard')->json('data.kpis'))->keyBy('key');

        $this->assertSame(['amount' => '250000.00', 'currency' => 'UGX'], $kpis['platform.mrr']['value']);
        $this->assertSame(['amount' => '250000.00', 'currency' => 'UGX'], $kpis['platform.revenue']['value']);
    }

    public function test_the_service_is_idempotent_across_runs(): void
    {
        $this->advanceTo('2026-03-31');
        $counts = $this->app->make(SubscriptionService::class)->advance(CarbonImmutable::parse('2026-03-31'));

        $this->assertSame(['grace' => 0, 'suspended' => 0, 'cancelled' => 0], $counts);
    }
}
