<?php

namespace App\Modules\Platform;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Platform\Application\PlatformWorkspace;
use App\Modules\Platform\Console\RecordBackup;
use App\Modules\Platform\Domain\Models\IntegrationProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([PlatformWorkspace::class], 'sfmtp.workspace-contributors');
    }

    public function boot(): void
    {
        Route::model('user', User::class);
        Route::model('integration', IntegrationProvider::class);

        if ($this->app->runningInConsole()) {
            $this->commands([RecordBackup::class]);
        }
    }
}
