<?php

namespace App\Modules\Access\Console;

use App\Modules\Access\Application\PermissionCatalog;
use Illuminate\Console\Command;

class SyncPermissions extends Command
{
    protected $signature = 'access:sync-permissions';

    protected $description = 'Sync the permissions table with PermissionRegistry (run on every deploy).';

    public function handle(PermissionCatalog $catalog): int
    {
        $catalog->sync();
        $this->info('Permissions synced.');

        return self::SUCCESS;
    }
}
