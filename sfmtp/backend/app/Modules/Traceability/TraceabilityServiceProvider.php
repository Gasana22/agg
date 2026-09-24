<?php

namespace App\Modules\Traceability;

use App\Modules\Traceability\Application\JourneyProjector;
use App\Modules\Traceability\Console\RefreshJourneysCommand;
use App\Modules\Traceability\Console\VerifyChain;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Modules\Traceability\Domain\Models\TraceEvent;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TraceabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(JourneyProjector::class);
    }

    public function boot(): void
    {
        // Bound through the models' farm scope: another farm's id is a 404.
        Route::model('batch', TraceBatch::class);
        Route::model('event', TraceEvent::class);

        if ($this->app->runningInConsole()) {
            $this->commands([VerifyChain::class, RefreshJourneysCommand::class]);
        }
    }
}
