<?php

namespace Tests\Feature\Access;

use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Traceability\Application\Recorder;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use Tests\TestCase;

/**
 * Role boundaries from docs/04-roles-and-permissions.md §6 that Phase 1 can
 * already exercise. Later phases extend this file as their modules land.
 */
class RoleBoundaryTest extends TestCase
{
    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->farm = $this->farm();
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/farms/{$this->farm->id}{$path}";
    }

    public function test_field_worker_is_limited_to_their_own_work(): void
    {
        $worker = $this->memberWithRole($this->farm, 'field_worker');

        foreach (['/traceability/batches', '/members', '/roles', '/audit-logs', '/dashboards/owner', '/dashboards/accountant'] as $path) {
            $this->assertSame(403, $this->asUser($worker)->getJson($this->url($path))->status(), $path);
        }
        $this->asUser($worker)->getJson($this->url('/dashboards/worker'))->assertOk();

        $ws = $this->asUser($worker)->getJson('/api/v1/me/workspaces')->json('data.0.permissions');
        foreach (array_keys($ws) as $permission) {
            $this->assertFalse(str_starts_with($permission, 'finance.'), "Worker holds {$permission}");
        }
        $this->assertArrayNotHasKey('inventory.values.view', $ws);
        $this->assertSame('assigned', $ws['tasks.view']);
    }

    public function test_agronomist_sees_crops_and_traceability_but_not_audit_or_roles_admin(): void
    {
        $agronomist = $this->memberWithRole($this->farm, 'agronomist');

        $this->asUser($agronomist)->getJson($this->url('/traceability/batches'))->assertOk();
        $this->asUser($agronomist)->postJson($this->url('/traceability/batches'), ['kind' => 'processed'])->assertCreated();
        $this->assertProblem($this->asUser($agronomist)->getJson($this->url('/audit-logs')), 403, 'forbidden');
        $this->assertProblem($this->asUser($agronomist)->getJson($this->url('/dashboards/livestock')), 403, 'dashboard_not_available');

        $permissions = $this->asUser($agronomist)->getJson('/api/v1/me/workspaces')->json('data.0.permissions');
        $this->assertArrayNotHasKey('livestock.animals.view', $permissions);
        $this->assertArrayNotHasKey('finance.view', $permissions);
    }

    public function test_accountant_cannot_change_operational_records(): void
    {
        $accountant = $this->memberWithRole($this->farm, 'accountant');
        $batch = $this->inFarm($this->farm, fn () => $this->app->make(Recorder::class)->createBatch(BatchKind::SeedLot));

        $this->asUser($accountant)->getJson($this->url('/traceability/batches'))->assertOk();
        $this->assertProblem($this->asUser($accountant)->postJson($this->url('/traceability/batches'), ['kind' => 'processed']), 403, 'forbidden');
        $this->assertProblem($this->asUser($accountant)->postJson($this->url("/traceability/batches/{$batch->id}/events"), ['event_type' => 'note']), 403, 'forbidden');
        $this->asUser($accountant)->getJson($this->url('/audit-logs'))->assertOk();
    }

    public function test_store_manager_cannot_manage_roles_or_publish(): void
    {
        $store = $this->memberWithRole($this->farm, 'store_manager');

        $permissions = $this->asUser($store)->getJson('/api/v1/me/workspaces')->json('data.0.permissions');
        $this->assertArrayNotHasKey('procurement.orders.manage', $permissions);
        $this->assertArrayNotHasKey('sales.pricing.manage', $permissions);
        $this->assertArrayNotHasKey('trace.publish', $permissions);
        $this->assertProblem($this->asUser($store)->getJson($this->url('/roles')), 403, 'forbidden');
    }

    public function test_manager_cannot_close_the_farm_or_edit_roles(): void
    {
        $manager = $this->memberWithRole($this->farm, 'manager');

        $this->assertProblem($this->asUser($manager)->deleteJson($this->url()), 403, 'forbidden');
        $this->asUser($manager)->getJson($this->url('/roles'))->assertOk();
        $roleId = collect($this->asUser($manager)->getJson($this->url('/roles'))->json('data'))->firstWhere('key', 'agronomist')['id'];
        $this->assertProblem($this->asUser($manager)->putJson($this->url("/roles/{$roleId}/permissions"), ['grants' => []]), 403, 'forbidden');
    }

    public function test_system_admin_has_no_way_into_farm_data(): void
    {
        $admin = $this->platformAdmin();

        foreach (['', '/traceability/batches', '/members', '/dashboards/owner', '/audit-logs'] as $path) {
            $this->assertSame(404, $this->asUser($admin)->getJson($this->url($path))->status(), $path);
        }
        $this->asUser($admin)->getJson('/api/v1/admin/dashboard')->assertOk();
        $this->asUser($admin)->getJson('/api/v1/me/workspaces')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'platform');
    }

    public function test_farm_members_cannot_reach_the_platform_surface(): void
    {
        $this->assertProblem($this->asUser($this->ownerOf($this->farm))->getJson('/api/v1/admin/dashboard'), 403, 'platform_admin_required');
    }

    public function test_portal_parties_cannot_reach_farms(): void
    {
        $party = $this->member(['user_type' => 'party']);

        $this->assertSame(404, $this->asUser($party)->getJson($this->url())->status());
        $this->assertProblem($this->asUser($party)->postJson('/api/v1/farms', ['name' => 'Supplier farm']), 403, 'member_account_required');
    }
}
