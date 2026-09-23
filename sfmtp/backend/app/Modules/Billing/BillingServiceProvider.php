<?php

namespace App\Modules\Billing;

use App\Modules\Billing\Application\BillingWorkspaces;
use App\Modules\Billing\Application\PlanLimitGate;
use App\Modules\Billing\Console\AdvanceSubscriptions;
use App\Modules\Billing\Domain\Models\Plan;
use App\Modules\Billing\Domain\Models\Subscription;
use App\Modules\Billing\Listeners\StartTrial;
use App\Modules\Tenancy\Contracts\SubscriptionGate;
use App\Modules\Tenancy\Domain\Events\OrganizationCreated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SubscriptionGate::class, PlanLimitGate::class);
        $this->app->tag([BillingWorkspaces::class], 'sfmtp.workspace-contributors');
    }

    public function boot(): void
    {
        Event::listen(OrganizationCreated::class, StartTrial::class);
        Route::model('plan', Plan::class);
        Route::model('subscription', Subscription::class);

        if ($this->app->runningInConsole()) {
            $this->commands([AdvanceSubscriptions::class]);
        }
    }
}
