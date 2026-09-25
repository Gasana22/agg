<?php

namespace App\Modules\Reporting;

use App\Modules\Reporting\Console\BenchDashboards;
use App\Modules\Reporting\Console\PruneExports;
use Illuminate\Support\ServiceProvider;

class ReportingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PruneExports::class, BenchDashboards::class]);
        }
    }
}
