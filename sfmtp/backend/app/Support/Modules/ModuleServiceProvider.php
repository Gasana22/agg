<?php

namespace App\Support\Modules;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Loads each business module's migrations and API routes
 * (app/Modules/<Module>/Database/migrations, app/Modules/<Module>/Routes/api.php).
 * Routes are mounted under /api/v1 with the `api` middleware group.
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach (config('sfmtp.modules') as $module) {
            $provider = "App\\Modules\\{$module}\\{$module}ServiceProvider";
            if (class_exists($provider)) {
                $this->app->register($provider);
            }
        }
    }

    public function boot(): void
    {
        foreach (config('sfmtp.modules') as $module) {
            $base = app_path("Modules/{$module}");

            if (is_dir("{$base}/Database/migrations")) {
                $this->loadMigrationsFrom("{$base}/Database/migrations");
            }

            if (! $this->app->routesAreCached() && is_file("{$base}/Routes/api.php")) {
                Route::prefix('api/v1')->middleware('api')->group("{$base}/Routes/api.php");
            }
        }
    }
}
