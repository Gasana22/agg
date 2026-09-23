<?php

namespace App\Modules\Support;

use App\Modules\Support\Application\SupportAccess;
use App\Modules\Support\Application\SupportWorkspaces;
use App\Modules\Support\Listeners\AuditSupportAccess;
use App\Modules\Tenancy\Contracts\SupportAccessResolver;
use App\Modules\Tenancy\Domain\Events\SupportAccessUsed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class SupportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SupportAccessResolver::class, SupportAccess::class);
        $this->app->tag([SupportWorkspaces::class], 'sfmtp.workspace-contributors');
    }

    public function boot(): void
    {
        Event::listen(SupportAccessUsed::class, AuditSupportAccess::class);
    }
}
