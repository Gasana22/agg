<?php

namespace App\Modules\Access;

use App\Modules\Access\Application\FarmMfaRequirement;
use App\Modules\Access\Listeners\InstallRoleTemplates;
use App\Modules\Identity\Contracts\MfaRequirement;
use App\Modules\Tenancy\Domain\Events\FarmCreated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MfaRequirement::class, FarmMfaRequirement::class);
    }

    public function boot(): void
    {
        Event::listen(FarmCreated::class, InstallRoleTemplates::class);

        if ($this->app->runningInConsole()) {
            $this->commands([Console\SyncPermissions::class]);
        }
    }
}
