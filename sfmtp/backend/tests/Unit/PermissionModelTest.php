<?php

namespace Tests\Unit;

use App\Modules\Access\Application\PermissionRegistry;
use App\Modules\Access\Application\RoleTemplates;
use App\Modules\Access\Domain\Enums\PermissionScope;
use App\Modules\Platform\Application\PlatformRoles;
use PHPUnit\Framework\TestCase;

/** Static guarantees of the permission model (docs/04). */
class PermissionModelTest extends TestCase
{
    public function test_the_widest_scope_wins(): void
    {
        $this->assertSame(PermissionScope::All, PermissionScope::Own->widest(PermissionScope::All));
        $this->assertSame(PermissionScope::Assigned, PermissionScope::Assigned->widest(PermissionScope::Own));
    }

    public function test_role_templates_only_use_known_permissions_and_supported_scopes(): void
    {
        $registry = PermissionRegistry::all();

        foreach (RoleTemplates::all() as $role => $template) {
            foreach ($template['grants'] as $permission => $scope) {
                $this->assertArrayHasKey($permission, $registry, "{$role} grants unknown {$permission}");
                $this->assertContains($scope, $registry[$permission]['scopes'], "{$role}: {$permission} does not support scope {$scope}");
                if ($role !== RoleTemplates::OWNER) {
                    $this->assertFalse($registry[$permission]['owner_only'], "{$role} holds owner-only {$permission}");
                }
            }
        }
    }

    public function test_the_field_worker_template_holds_no_money_permission(): void
    {
        $registry = PermissionRegistry::all();

        foreach (array_keys(RoleTemplates::all()['field_worker']['grants']) as $permission) {
            $this->assertFalse($registry[$permission]['money'], "field_worker holds money permission {$permission}");
        }
    }

    public function test_platform_roles_use_known_capabilities_and_only_support_can_enter_farms(): void
    {
        foreach (PlatformRoles::roles() as $role => $capabilities) {
            foreach ($capabilities as $capability) {
                $this->assertArrayHasKey($capability, PlatformRoles::CAPABILITIES, "{$role}: unknown {$capability}");
            }
            $this->assertSame($role === 'support', in_array('support.access', $capabilities, true), $role);
        }
    }
}
