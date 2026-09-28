<?php

namespace App\Modules\Integrations;

use App\Modules\Integrations\Email\EmailGateway;
use App\Modules\Integrations\Email\ProviderMailTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

/**
 * Adapters to external providers (ADR-0018): SMS, email, weather, maps and
 * payments, each chosen and failed over by the Router from the providers
 * configured in the admin portal.
 */
class IntegrationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Mail::extend('providers', fn () => new ProviderMailTransport($this->app->make(EmailGateway::class)));
    }
}
