<?php

namespace App\Modules\Platform\Application;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;

class PlatformPermissions
{
    /** @var array<string, array<int,string>> */
    private array $cache = [];

    /** @return array<int,string> role keys */
    public function rolesOf(User $user): array
    {
        if (! $user->isPlatformAdmin()) {
            return [];
        }

        return DB::table('platform_user_roles')
            ->join('platform_roles', 'platform_roles.id', '=', 'platform_user_roles.platform_role_id')
            ->where('platform_user_roles.user_id', $user->id)
            ->orderBy('platform_roles.key')
            ->pluck('platform_roles.key')
            ->all();
    }

    /** @return array<int,string> */
    public function capabilitiesOf(User $user): array
    {
        return $this->cache[$user->id] ??= (function () use ($user) {
            $caps = [];
            foreach ($this->rolesOf($user) as $role) {
                $caps = array_merge($caps, PlatformRoles::roles()[$role] ?? []);
            }
            $caps = array_values(array_unique($caps));
            sort($caps);

            return $caps;
        })();
    }

    public function allows(User $user, string $capability): bool
    {
        return in_array($capability, $this->capabilitiesOf($user), true);
    }

    public function assign(User $user, string $role): void
    {
        $roleId = DB::table('platform_roles')->where('key', $role)->value('id');
        DB::table('platform_user_roles')->insertOrIgnore(['user_id' => $user->id, 'platform_role_id' => $roleId, 'created_at' => now()]);
        unset($this->cache[$user->id]);
    }
}
