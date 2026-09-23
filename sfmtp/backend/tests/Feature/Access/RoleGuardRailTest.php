<?php

namespace Tests\Feature\Access;

use App\Modules\Access\Application\FarmPermissions;
use App\Modules\Access\Domain\Enums\PermissionScope;
use App\Modules\Access\Domain\Models\FarmRole;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoleGuardRailTest extends TestCase
{
    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->farm = $this->farm();
    }

    private function update(string $roleKey, array $grants)
    {
        $roleId = $this->inFarm($this->farm, fn () => FarmRole::where('key', $roleKey)->value('id'));

        return $this->asUser($this->ownerOf($this->farm))
            ->putJson("/api/v1/farms/{$this->farm->id}/roles/{$roleId}/permissions", ['grants' => $grants]);
    }

    public function test_owner_can_adjust_a_role_and_the_change_is_audited(): void
    {
        $this->update('agronomist', ['trace.batches.view' => 'all', 'tasks.view' => 'assigned'])
            ->assertOk()
            ->assertJsonPath('data.grants', ['tasks.view' => 'assigned', 'trace.batches.view' => 'all']);

        $this->assertDatabaseHas('audit_logs', ['farm_id' => $this->farm->id, 'action' => 'role.permissions_changed']);
    }

    public function test_owner_only_permissions_cannot_be_delegated(): void
    {
        $this->update('manager', ['farm.delete' => 'all'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'guard_rail_violation')
            ->assertJsonValidationErrors('grants.farm.delete');
    }

    public function test_field_worker_roles_can_never_hold_money_permissions(): void
    {
        $this->update('field_worker', ['worker.self' => 'all', 'finance.view' => 'all', 'inventory.values.view' => 'all'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['grants.finance.view', 'grants.inventory.values.view']);
    }

    public function test_unknown_permissions_and_unsupported_scopes_are_rejected(): void
    {
        $this->update('manager', ['crops.teleport' => 'all', 'farm.profile.view' => 'own'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['grants.crops.teleport', 'grants.farm.profile.view']);
    }

    public function test_the_owner_role_is_locked(): void
    {
        $this->assertProblem($this->update('owner', []), 403, 'role_locked');
    }

    public function test_effective_permissions_are_the_union_with_the_widest_scope(): void
    {
        $user = $this->memberWithRole($this->farm, 'field_worker');
        $membership = FarmUser::where(['farm_id' => $this->farm->id, 'user_id' => $user->id])->first();

        $this->inFarm($this->farm, function () use ($membership) {
            DB::table('farm_user_roles')->insert([
                'farm_id' => $this->farm->id,
                'farm_user_id' => $membership->id,
                'farm_role_id' => FarmRole::where('key', 'manager')->value('id'),
            ]);
        });

        $grants = $this->app->make(FarmPermissions::class)->for($membership);
        $this->assertSame(PermissionScope::All, $grants['tasks.view']);       // assigned ∪ all
        $this->assertSame(PermissionScope::Own, $grants['attendance.record']); // only via worker
        $this->assertArrayHasKey('tasks.verify', $grants);                     // only via manager
    }
}
