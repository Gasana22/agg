<?php

namespace App\Modules\Livestock;

use App\Modules\Livestock\Domain\Models\Animal;
use App\Modules\Livestock\Domain\Models\AnimalGroup;
use App\Modules\Livestock\Domain\Models\Breeding;
use App\Modules\Livestock\Domain\Models\Feeding;
use App\Modules\Livestock\Domain\Models\HealthRecord;
use App\Modules\Livestock\Domain\Models\ProductionRecord;
use App\Modules\Livestock\Domain\Models\SaleRequest;
use App\Modules\Livestock\Domain\Models\Weight;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class LivestockServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Bound through the models' farm scope: another farm's id is a 404.
        Route::model('group', AnimalGroup::class);
        Route::model('animal', Animal::class);
        Route::model('health_record', HealthRecord::class);
        Route::model('feeding', Feeding::class);
        Route::model('weight', Weight::class);
        Route::model('production', ProductionRecord::class);
        Route::model('breeding', Breeding::class);
        Route::model('sale', SaleRequest::class);
    }
}
