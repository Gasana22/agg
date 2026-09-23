<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Domain\Models\Plan;
use App\Modules\Billing\Domain\Models\Subscription;
use App\Modules\Tenancy\Application\FarmService;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Support\Http\ApiException;
use Tests\TestCase;

class PlanLimitsTest extends TestCase
{
    private function onPlan(Farm $farm, array $limits): void
    {
        $plan = Plan::create(['code' => 'tiny_'.uniqid(), 'name' => 'Tiny', 'price' => 1000, 'currency' => 'UGX', 'billing_period' => 'monthly', 'features' => []] + $limits);
        Subscription::where('organization_id', $farm->organization_id)->update(['plan_id' => $plan->id]);
    }

    public function test_the_farm_limit_stops_extra_farms(): void
    {
        $farm = $this->farm();
        $owner = $this->ownerOf($farm);
        $this->onPlan($farm, ['max_farms' => 1]);

        $response = $this->asUser($owner)->postJson('/api/v1/farms', ['name' => 'Second farm']);

        $this->assertProblem($response, 403, 'plan_limit_reached');
        $response->assertJsonPath('limit', ['resource' => 'farms', 'limit' => 1, 'used' => 1, 'plan' => Subscription::first()->plan->code]);
    }

    public function test_closed_farms_free_their_slot(): void
    {
        $farm = $this->farm();
        $owner = $this->ownerOf($farm);
        $this->onPlan($farm, ['max_farms' => 1]);
        $farm->forceFill(['status' => 'closed'])->save();

        $this->asUser($owner)->postJson('/api/v1/farms', ['name' => 'Fresh start'])->assertCreated();
    }

    public function test_the_user_limit_counts_distinct_people_across_farms(): void
    {
        $a = $this->farm();
        $owner = $this->ownerOf($a);
        $b = $this->farm($owner);
        $this->onPlan($a, ['max_users' => 2]);
        $service = $this->app->make(FarmService::class);

        $agronomist = $this->member();
        $service->addMember($a, $agronomist);            // owner + agronomist = 2
        $service->addMember($b, $agronomist);            // same person on another farm: free

        try {
            $service->addMember($a, $this->member());   // a third person
            $this->fail('User limit not enforced');
        } catch (ApiException $e) {
            $this->assertSame('plan_limit_reached', $e->errorCode);
            $this->assertSame(['resource' => 'users', 'limit' => 2, 'used' => 2, 'plan' => Subscription::first()->plan->code], $e->extra['limit']);
        }
    }

    public function test_owners_change_plans_within_their_usage(): void
    {
        $farm = $this->farm();
        $owner = $this->ownerOf($farm);
        $this->farm($owner);   // two farms

        $this->asUser($owner)->getJson('/api/v1/billing/plans')->assertOk()->assertJsonCount(2, 'data');   // enterprise is not public
        $this->asUser($owner)->postJson('/api/v1/billing/subscription/change-plan', ['plan_code' => 'starter'])
            ->assertUnprocessable()->assertJsonPath('code', 'plan_limit_exceeded');
        $this->asUser($owner)->postJson('/api/v1/billing/subscription/change-plan', ['plan_code' => 'enterprise'])
            ->assertUnprocessable()->assertJsonValidationErrors('plan_code');

        // A billing admin can move them to a non-public plan.
        $sub = Subscription::where('organization_id', $farm->organization_id)->first();
        $this->asUser($this->platformAdmin(['billing']))->postJson("/api/v1/admin/subscriptions/{$sub->id}/change-plan", ['plan_code' => 'enterprise'])
            ->assertOk()->assertJsonPath('data.plan.code', 'enterprise')->assertJsonPath('data.usage.farms.limit', null);
    }

    public function test_only_the_organization_owner_manages_billing(): void
    {
        $farm = $this->farm();
        $accountant = $this->memberWithRole($farm, 'accountant');

        $this->assertProblem($this->asUser($accountant)->getJson('/api/v1/billing/subscription'), 403, 'billing_owner_only');
        $this->asUser($this->ownerOf($farm))->getJson('/api/v1/billing/subscription')->assertOk()
            ->assertJsonPath('data.plan.code', 'growth')
            ->assertJsonPath('data.usage.users', ['used' => 2, 'limit' => 50]);
    }

    public function test_plans_are_configurable_but_codes_are_permanent(): void
    {
        $billing = $this->platformAdmin(['billing']);

        $plan = $this->asUser($billing)->postJson('/api/v1/admin/plans', [
            'code' => 'coop', 'name' => 'Cooperative', 'price' => 400000, 'currency' => 'ugx', 'billing_period' => 'monthly',
            'max_farms' => 20, 'max_users' => 200, 'features' => ['qr_codes'],
        ])->assertCreated()->assertJsonPath('data.price', ['amount' => '400000.00', 'currency' => 'UGX'])->json('data');

        $this->asUser($billing)->patchJson("/api/v1/admin/plans/{$plan['id']}", ['price' => 450000, 'is_public' => false])->assertOk()->assertJsonPath('data.is_public', false);
        $this->asUser($billing)->patchJson("/api/v1/admin/plans/{$plan['id']}", ['code' => 'renamed'])->assertUnprocessable();
        $this->asUser($billing)->postJson('/api/v1/admin/plans', ['code' => 'coop', 'name' => 'Dup', 'price' => 1, 'currency' => 'UGX', 'billing_period' => 'monthly'])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.plan_updated']);
    }
}
