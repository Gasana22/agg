<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
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
    }
}
