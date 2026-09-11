<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * The 10 SFMTP user roles from the spec.
     */
    public const ROLES = [
        'system_administrator',
        'farm_owner',
        'farm_manager',
        'agronomist',
        'livestock_manager',
        'store_manager',
        'accountant',
        'field_worker',
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
