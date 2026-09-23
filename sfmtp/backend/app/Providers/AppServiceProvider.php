<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Surface N+1 queries, silently discarded attributes and missing
        // attributes during development and tests.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Client IPs for rate limits and the audit log come from X-Forwarded-For
        // only when the request arrives through a trusted proxy.
        $proxies = trim((string) config('sfmtp.trusted_proxies'));
        if ($proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
    }
}
