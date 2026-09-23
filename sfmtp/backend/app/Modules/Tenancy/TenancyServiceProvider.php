<?php

namespace App\Modules\Tenancy;

use App\Modules\Tenancy\Contracts\NoSupportAccess;
use App\Modules\Tenancy\Contracts\NullSubscriptionGate;
use App\Modules\Tenancy\Contracts\SubscriptionGate;
use App\Modules\Tenancy\Contracts\SupportAccessResolver;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Fresh per request and per queued job (Octane/queue safe).
        $this->app->scoped(TenantContext::class);

        // Replaced by the Billing and Support modules.
        $this->app->bindIf(SubscriptionGate::class, NullSubscriptionGate::class);
        $this->app->bindIf(SupportAccessResolver::class, NoSupportAccess::class);
    }
}
