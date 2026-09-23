<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Access\Domain\Models\FarmRole;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Modules\Tenancy\MissingTenantContext;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Traceability\Application\Recorder;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class FarmTenancyTest extends TestCase
{
    public function test_creating_a_farm_makes_the_creator_owner_with_role_templates(): void
    {
        $user = $this->member();

        $response = $this->asUser($user)->postJson('/api/v1/farms', [
            'name' => 'Kakiri Dairy', 'district' => 'Wakiso', 'size_ha' => 35.5, 'currency' => 'ugx',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.currency', 'UGX');

        $farmId = $response->json('data.id');
        $this->assertTrue(FarmUser::where(['farm_id' => $farmId, 'user_id' => $user->id, 'is_owner' => true])->exists());
        $this->assertSame(7, DB::table('farm_roles')->where('farm_id', $farmId)->count());
        $this->assertDatabaseHas('farm_settings', ['farm_id' => $farmId]);

        // The owner role makes MFA mandatory from now on.
        $this->assertProblem($this->asUser($user)->getJson("/api/v1/farms/{$farmId}"), 403, 'mfa_setup_required');
        $this->withMfa($user);
        $this->asUser($user)->getJson("/api/v1/farms/{$farmId}")->assertOk()->assertJsonPath('data.name', 'Kakiri Dairy');
    }

    public function test_workspaces_list_farms_roles_permissions_and_dashboards(): void
    {
        $farm = $this->farm();
        $agronomist = $this->memberWithRole($farm, 'agronomist');

        $ws = $this->asUser($agronomist)->getJson('/api/v1/me/workspaces')->assertOk()->json();

        $this->assertCount(1, $ws['data']);
        $this->assertSame($farm->id, $ws['data'][0]['id']);
        $this->assertSame(['agronomist'], $ws['data'][0]['dashboards']);
        $this->assertArrayHasKey('crops.harvest.record', $ws['data'][0]['permissions']);
        $this->assertArrayNotHasKey('finance.view', $ws['data'][0]['permissions']);
        $this->assertFalse($ws['meta']['mfa_required']);
    }

    public function test_only_member_accounts_can_create_or_join_farms(): void
    {
        $admin = $this->withMfa($this->member(['user_type' => 'platform_admin']));
        $this->assertProblem($this->asUser($admin)->postJson('/api/v1/farms', ['name' => 'Nope']), 403, 'member_account_required');

        $farm = $this->farm();
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('SFMTP_MEMBER_ONLY');
        FarmUser::create(['farm_id' => $farm->id, 'user_id' => $admin->id, 'status' => 'active']);
    }

    public function test_a_farm_has_exactly_one_owner(): void
    {
        $farm = $this->farm();

        $this->expectException(QueryException::class);
        FarmUser::create(['farm_id' => $farm->id, 'user_id' => $this->member()->id, 'status' => 'active', 'is_owner' => true]);
    }

    public function test_farm_scoped_models_refuse_to_run_without_tenant_context(): void
    {
        $this->expectException(MissingTenantContext::class);
        TraceBatch::count();
    }

    public function test_a_record_cannot_be_created_for_another_farm(): void
    {
        [$a, $b] = [$this->farm(), $this->farm()];

        $this->expectException(LogicException::class);
        $this->inFarm($a, fn () => TraceBatch::create(['farm_id' => $b->id, 'batch_code' => 'SFM-TEST-0001', 'kind' => 'seed_lot']));
    }

    public function test_composite_foreign_keys_reject_cross_farm_references(): void
    {
        [$a, $b] = [$this->farm(), $this->farm()];
        $roleOfB = $this->inFarm($b, fn () => FarmRole::where('key', 'manager')->value('id'));
        $memberOfA = FarmUser::where('farm_id', $a->id)->value('id');

        // A membership of farm A cannot hold a role defined by farm B.
        $this->expectException(QueryException::class);
        DB::table('farm_user_roles')->insert(['farm_id' => $a->id, 'farm_user_id' => $memberOfA, 'farm_role_id' => $roleOfB]);
    }

    public function test_row_level_security_hides_other_farms_from_raw_sql(): void
    {
        if (! $this->isPgsql()) {
            $this->markTestSkipped('Row-level security is PostgreSQL-only (docs/02 §6).');
        }

        [$a, $b] = [$this->farm(), $this->farm()];
        $recorder = $this->app->make(Recorder::class);
        $this->inFarm($a, fn () => $recorder->createBatch(BatchKind::SeedLot));
        $this->inFarm($b, fn () => $recorder->createBatch(BatchKind::SeedLot));

        // No context: nothing is visible, even to raw SQL.
        $this->assertSame(0, (int) DB::selectOne('select count(*) as c from trace_batches')->c);

        // Farm A context: raw SQL without any WHERE still only sees farm A.
        $rows = $this->inFarm($a, fn () => DB::select('select farm_id from trace_batches'));
        $this->assertSame([$a->id], array_values(array_unique(array_column($rows, 'farm_id'))));

        // And writes into another farm are rejected by the policy's WITH CHECK.
        $this->expectException(QueryException::class);
        $this->inFarm($a, fn () => DB::table('trace_batches')->insert([
            'id' => (string) Str::uuid7(), 'farm_id' => $b->id, 'batch_code' => 'SFM-RLS0-TEST', 'kind' => 'seed_lot', 'status' => 'open',
        ]));
    }

    public function test_suspended_farms_are_blocked_and_closed_farms_disappear(): void
    {
        $farm = $this->farm();
        $owner = $this->ownerOf($farm);

        $farm->forceFill(['status' => 'suspended'])->save();
        $this->assertProblem($this->asUser($owner)->getJson("/api/v1/farms/{$farm->id}"), 403, 'farm_suspended');

        $farm->forceFill(['status' => 'active'])->save();
        $this->asUser($owner)->deleteJson("/api/v1/farms/{$farm->id}")->assertNoContent();
        $this->assertProblem($this->asUser($owner)->getJson("/api/v1/farms/{$farm->id}"), 404, 'not_found');
        $this->asUser($owner)->getJson('/api/v1/farms')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_tenant_context_is_cleared_after_each_request(): void
    {
        $farm = $this->farm();
        $this->asUser($this->ownerOf($farm))->getJson("/api/v1/farms/{$farm->id}")->assertOk();

        $this->assertFalse($this->app->make(TenantContext::class)->hasFarm());
        if ($this->isPgsql()) {
            $this->assertSame('', DB::selectOne("select current_setting('app.farm_id', true) as v")->v);
        }
    }

    public function test_profile_updates_are_validated_and_saved(): void
    {
        $farm = $this->farm();
        $owner = $this->ownerOf($farm);

        $this->asUser($owner)->patchJson("/api/v1/farms/{$farm->id}", ['size_ha' => -1])->assertUnprocessable();
        $this->asUser($owner)->patchJson("/api/v1/farms/{$farm->id}", ['village' => 'Seeta'])->assertOk()->assertJsonPath('data.village', 'Seeta');
        $this->assertSame('Seeta', Farm::find($farm->id)->village);
    }
}
