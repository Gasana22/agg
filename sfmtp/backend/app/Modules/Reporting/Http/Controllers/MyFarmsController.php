<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Access\Application\Dashboards;
use App\Modules\Access\Application\FarmPermissions;
use App\Modules\Reporting\Application\FarmMetrics;
use App\Modules\Tenancy\Domain\Enums\FarmStatus;
use App\Modules\Tenancy\Domain\Enums\MembershipStatus;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /me/farms/overview — "My farms": a card per farm with the headline
 * numbers the member is allowed to see there. Each farm is read inside its
 * own tenant context, so row-level security applies as usual.
 */
class MyFarmsController
{
    public function __invoke(Request $request, TenantContext $context, FarmPermissions $permissions, FarmMetrics $metrics): JsonResponse
    {
        $memberships = FarmUser::with('farm')
            ->where('user_id', $request->user()->id)
            ->where('status', MembershipStatus::Active->value)
            ->get()
            ->filter(fn (FarmUser $m) => $m->farm && $m->farm->status !== FarmStatus::Closed)
            ->sortBy(fn (FarmUser $m) => $m->farm->name)
            ->values();

        $roles = DB::table('farm_user_roles')
            ->join('farm_roles', 'farm_roles.id', '=', 'farm_user_roles.farm_role_id')
            ->whereIn('farm_user_roles.farm_user_id', $memberships->pluck('id'))
            ->orderBy('farm_roles.name')
            ->get(['farm_user_roles.farm_user_id', 'farm_roles.name'])
            ->groupBy('farm_user_id');

        $cards = $memberships->map(fn (FarmUser $m) => $context->run($m->farm, function () use ($m, $permissions, $metrics, $roles) {
            $grants = $permissions->for($m);
            $can = fn (string $p) => isset($grants[$p]);
            $farm = $m->farm;

            return [
                'id' => $farm->id,
                'type' => 'farm_overview',
                'name' => $farm->name,
                'code' => $farm->code,
                'status' => $farm->status->value,
                'district' => $farm->district,
                'is_owner' => $m->is_owner,
                'roles' => $m->is_owner ? ['Farm Owner'] : ($roles[$m->id] ?? collect())->pluck('name')->values()->all(),
                'home_dashboard' => Dashboards::available($grants)[0] ?? null,
                'metrics' => array_filter([
                    'size_ha' => $can('farm.profile.view') && $farm->size_ha !== null ? (float) $farm->size_ha : null,
                    'plots' => $can('structure.view') ? $metrics->plots() : null,
                    'mapped_area_ha' => $can('structure.view') ? $metrics->mappedAreaHa() : null,
                    'members' => $can('members.view') ? $metrics->membersActive() : null,
                    'open_batches' => $can('trace.batches.view') ? $metrics->openBatches() : null,
                ], fn ($v) => $v !== null),
            ];
        }, $m));

        return new JsonResponse(['data' => $cards->all()]);
    }
}
