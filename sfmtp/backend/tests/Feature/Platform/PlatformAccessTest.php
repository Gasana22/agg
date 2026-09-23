<?php

namespace Tests\Feature\Platform;

use Tests\TestCase;

/** Platform roles and capabilities (docs/04 §5). */
class PlatformAccessTest extends TestCase
{
    public function test_farm_members_cannot_reach_any_admin_route(): void
    {
        $owner = $this->ownerOf($this->farm());

        foreach (['/admin/farms', '/admin/plans', '/admin/subscriptions', '/admin/users', '/admin/settings', '/admin/integrations', '/admin/system/health', '/admin/catalog/crops', '/admin/support/tickets'] as $path) {
            $this->assertProblem($this->asUser($owner)->getJson('/api/v1'.$path), 403, 'platform_admin_required');
        }
    }

    public function test_platform_staff_without_a_role_can_do_nothing(): void
    {
        $nobody = $this->platformAdmin([]);

        $this->assertProblem($this->asUser($nobody)->getJson('/api/v1/admin/dashboard'), 403, 'forbidden');
        $this->assertProblem($this->asUser($nobody)->getJson('/api/v1/admin/farms'), 403, 'forbidden');
    }

    public function test_each_platform_role_gets_only_its_capabilities(): void
    {
        $farm = $this->farm();
        $farm->forceFill(['status' => 'pending'])->save();
        $support = $this->platformAdmin(['support']);
        $billing = $this->platformAdmin(['billing']);

        $this->asUser($support)->getJson('/api/v1/admin/farms')->assertOk();
        $this->assertProblem($this->asUser($support)->postJson("/api/v1/admin/farms/{$farm->id}/approve"), 403, 'forbidden');
        $this->assertProblem($this->asUser($support)->getJson('/api/v1/admin/plans'), 403, 'forbidden');
        $this->asUser($support)->getJson('/api/v1/admin/support/tickets')->assertOk();

        $this->asUser($billing)->getJson('/api/v1/admin/plans')->assertOk();
        $this->assertProblem($this->asUser($billing)->getJson('/api/v1/admin/catalog/crops'), 403, 'forbidden');
        $this->assertProblem($this->asUser($billing)->getJson('/api/v1/admin/system/health'), 403, 'forbidden');
        $this->assertProblem($this->asUser($billing)->postJson("/api/v1/admin/farms/{$farm->id}/approve"), 403, 'forbidden');
    }

    public function test_the_admin_workspace_lists_roles_and_capabilities(): void
    {
        $ws = $this->asUser($this->platformAdmin(['billing']))->getJson('/api/v1/me/workspaces')->assertOk()->json('data.0');

        $this->assertSame('platform', $ws['type']);
        $this->assertSame(['admin'], $ws['dashboards']);
        $this->assertSame([['key' => 'billing', 'name' => 'Billing']], $ws['roles']);
        $this->assertArrayHasKey('plans.manage', $ws['permissions']);
        $this->assertArrayNotHasKey('catalog.manage', $ws['permissions']);
    }

    public function test_no_platform_role_reaches_farm_data(): void
    {
        $farm = $this->farm();

        foreach (['super_admin', 'support', 'billing'] as $role) {
            $admin = $this->platformAdmin([$role]);
            $this->assertSame(404, $this->asUser($admin)->getJson("/api/v1/farms/{$farm->id}/traceability/batches")->status(), $role);
        }
    }
}
