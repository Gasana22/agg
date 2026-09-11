<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Platform-wide roles, assigned directly to a user (Spatie).
     *
     * The other 7 roles in the spec (farm_owner, farm_manager, agronomist,
     * livestock_manager, store_manager, accountant, field_worker) are NOT
     * global roles: they describe a user's position on one specific farm,
     * which can differ per farm, and live in farm_user.role_on_farm
     * (see App\Enums\FarmRole) instead. system_administrator manages the
     * platform itself — farms, users, system settings — not any one farm's
     * day-to-day operations.
     */
    public const ROLES = [
        'system_administrator',
        'supplier',
        'customer',
    ];

    public function run(): void
    {
        foreach (self::ROLES as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'api']);
        }
    }
}
