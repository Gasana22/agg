<?php

namespace Database\Seeders;

use App\Modules\Access\Application\PermissionCatalog;
use App\Modules\Catalog\Database\seeders\CatalogSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(PermissionCatalog $permissions): void
    {
        $permissions->sync();
        $this->call(CatalogSeeder::class);

        if (app()->environment(['local', 'testing'])) {
            $this->call(DemoSeeder::class);
        }
    }
}
