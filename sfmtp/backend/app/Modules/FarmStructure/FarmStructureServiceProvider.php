<?php

namespace App\Modules\FarmStructure;

use App\Modules\FarmStructure\Domain\Models\Block;
use App\Modules\FarmStructure\Domain\Models\Location;
use App\Modules\FarmStructure\Domain\Models\Plot;
use App\Modules\FarmStructure\Domain\Models\Section;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class FarmStructureServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Bound through the models' farm scope: another farm's id, or an
        // archived record, is a 404.
        Route::model('block', Block::class);
        Route::model('section', Section::class);
        Route::model('plot', Plot::class);
        Route::model('location', Location::class);
    }
}
