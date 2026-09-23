<?php

namespace Database\Seeders;

use App\Modules\Access\Application\PermissionCatalog;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(PermissionCatalog $permissions): void
    {
        $permissions->sync();

        if (app()->environment(['local', 'testing'])) {
            $this->call(DemoSeeder::class);
        }
    }
}
