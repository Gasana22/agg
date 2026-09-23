<?php

namespace App\Modules\Access\Application;

use App\Modules\Identity\Contracts\MfaRequirement;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * MFA is mandatory for platform admins, farm owners, members holding a
 * role listed in sfmtp.security.mfa_required_farm_roles, and every member of
 * a farm whose settings require it (docs/01 §6).
 */
class FarmMfaRequirement implements MfaRequirement
{
    public function requiresMfa(User $user): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        $memberships = DB::table('farm_users')
            ->join('farms', 'farms.id', '=', 'farm_users.farm_id')
            ->where('farm_users.user_id', $user->id)
            ->where('farm_users.status', 'active')
            ->whereIn('farms.status', ['pending', 'active'])
            ->get(['farm_users.id', 'farm_users.farm_id', 'farm_users.is_owner']);

        if ($memberships->isEmpty()) {
            return false;
        }

        if ($memberships->contains(fn ($m) => (bool) $m->is_owner)) {
            return true;
        }

        $hasRequiredRole = DB::table('farm_user_roles')
            ->join('farm_roles', 'farm_roles.id', '=', 'farm_user_roles.farm_role_id')
            ->whereIn('farm_user_roles.farm_user_id', $memberships->pluck('id'))
            ->whereIn('farm_roles.key', config('sfmtp.security.mfa_required_farm_roles'))
            ->exists();

        if ($hasRequiredRole) {
            return true;
        }

        return DB::table('farm_settings')
            ->whereIn('farm_id', $memberships->pluck('farm_id'))
            ->pluck('settings')
            ->contains(fn ($json) => (bool) (json_decode($json, true)['require_mfa_for_all'] ?? false));
    }
}
