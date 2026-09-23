<?php

namespace App\Modules\Billing\Application;

use App\Modules\Access\Contracts\WorkspaceContributor;
use App\Modules\Billing\Domain\Models\Subscription;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Adds each farm's subscription state to /me/workspaces so the web app can
 * warn during the grace period and explain a blocked farm. Details (price,
 * payments) stay behind /billing for the owner.
 */
class BillingWorkspaces implements WorkspaceContributor
{
    public function contribute(User $user, array $workspaces): array
    {
        $farmIds = array_column(array_filter($workspaces, fn ($w) => ($w['type'] ?? null) === 'farm'), 'id');
        if ($farmIds === []) {
            return $workspaces;
        }

        $orgByFarm = DB::table('farms')->whereIn('id', $farmIds)->pluck('organization_id', 'id');
        $subs = Subscription::whereIn('organization_id', $orgByFarm->unique()->values())->get()->keyBy('organization_id');

        foreach ($workspaces as &$workspace) {
            if (($workspace['type'] ?? null) !== 'farm') {
                continue;
            }
            $s = $subs[$orgByFarm[$workspace['id']] ?? null] ?? null;
            $workspace['subscription'] = $s ? [
                'status' => $s->status->value,
                'current_period_end' => $s->current_period_end->toDateString(),
                'grace_until' => $s->grace_until?->toDateString(),
                'cancel_at_period_end' => $s->cancel_at_period_end,
            ] : null;
        }

        return $workspaces;
    }
}
