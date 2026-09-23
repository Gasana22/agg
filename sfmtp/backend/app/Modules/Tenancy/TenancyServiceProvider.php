<?php

namespace App\Modules\Tenancy;

use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Fresh per request and per queued job (Octane/queue safe).
        $this->app->scoped(TenantContext::class);
    }
}
