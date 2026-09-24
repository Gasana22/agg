<?php

namespace App\Modules\Parties;

use App\Modules\Parties\Application\PartyContext;
use App\Modules\Parties\Application\PartyWorkspaces;
use App\Modules\Parties\Application\PortalSubjects;
use Illuminate\Support\ServiceProvider;

class PartiesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Fresh for every request and queued job, like TenantContext.
        $this->app->scoped(PartyContext::class);
        $this->app->scoped(PortalSubjects::class);
        $this->app->tag([PartyWorkspaces::class], 'sfmtp.workspace-contributors');
    }
}
