<?php

namespace Database\Seeders;

use App\Models\Farm;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        // Platform-wide: manages the system (farms, users, settings), not
        // any single farm's operations. No farm membership at all.
        $admin = User::factory()->create([
            'name' => 'System Admin',
            'email' => 'admin@farmsap.test',
            'password' => bcrypt('password'),
        ]);
        $admin->assignRole('system_administrator');

        // Farm-scoped: owns and works one farm. farm_owner lives on the
        // farm_user pivot (auto-attached by Farm::booted()), not as a
        // global role on the user.
        $owner = User::factory()->create([
            'name' => 'Andrew Gakuba Gasana',
            'email' => 'owner@aggfarm.test',
            'password' => bcrypt('password'),
        ]);

        Farm::create([
            'owner_id' => $owner->id,
            'name' => 'AGG Farm',
            'district' => 'Kayonza',
            'village' => 'Rwinkwavu',
        ]);
    }
}
